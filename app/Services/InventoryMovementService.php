<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InventoryException;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sumber kebenaran tunggal untuk setiap perubahan stok (PRD Modul 2).
 *
 * Setiap metode membungkus tiga hal dalam SATU DB::transaction():
 *   1. perubahan products.current_stock
 *   2. pencatatan di buku besar inventory_movements
 *   3. jejak rekam di audit_logs
 *
 * Additionally, peringatan stok menipis dipicu dari dalam transaksi yang sama
 * (UseCase: "Kirim Peringatan Stok Menipis").
 * Controller tidak boleh mengubah stok langsung; semuanya lewat kelas ini.
 *
 * Konvensi delta: inventory_movements.quantity menyimpan selisih bertanda,
 * sehingga SUM(quantity) per produk selalu sama dengan current_stock.
 */
final class InventoryMovementService
{
    /** Label aksi yang dipakai pada audit_logs. */
    private const AUDIT_PREFIX = [
        MovementType::Inbound->value => 'inbound',
        MovementType::Outbound->value => 'outbound',
        MovementType::Adjustment->value => 'stock_opname',
    ];

    // -----------------------------------------------------------------
    // API publik
    // -----------------------------------------------------------------

    /**
     * Pencatatan Inbound: penerimaan barang dari pemasok.
     *
     * @param  Product  $product  produk yang menerima barang
     * @param  int  $quantity  jumlah barang masuk, harus > 0
     * @param  User|null  $user  admin gudang yang mencatat
     * @param  int|null  $supplierId  pemasok (referensi faktur), opsional
     * @param  string|null  $referenceCode  nomor faktur / dokumen
     * @param  CarbonInterface|null  $movementDate  tanggal transaksi, default sekarang
     * @param  float|null  $unitPrice  harga beli satuan; bila diberikan dan
     *                                 pemasok belum terhubung ke produk, relasi
     *                                 pivot dibuat sekalian (PRD Modul 1)
     *
     * @throws InventoryException
     */
    public function recordInbound(
        Product $product,
        int $quantity,
        ?User $user = null,
        ?int $supplierId = null,
        ?string $referenceCode = null,
        ?CarbonInterface $movementDate = null,
        ?float $unitPrice = null,
    ): InventoryMovement {
        if ($quantity <= 0) {
            throw InventoryException::invalidQuantity('Inbound');
        }

        if ($unitPrice !== null && $unitPrice < 0) {
            throw InventoryException::unitPriceNegative($unitPrice);
        }

        if ($supplierId !== null && ! Supplier::whereKey($supplierId)->exists()) {
            throw InventoryException::supplierNotFound($supplierId);
        }

        return $this->applyMovement(
            product: $product,
            type: MovementType::Inbound,
            deltaResolver: fn (): int => $quantity,
            user: $user,
            supplierId: $supplierId,
            referenceCode: $referenceCode,
            movementDate: $movementDate,
            afterSave: $supplierId !== null && $unitPrice !== null
                ? fn (InventoryMovement $movement, Product $locked) => $this->recordPurchasePrice($locked, $supplierId, $unitPrice)
                : null,
        );
    }

    /**
     * Pencatatan Outbound: pengeluaran barang.
     *
     * @param  int  $quantity  jumlah barang keluar, harus > 0
     *
     * @throws InsufficientStockException bila current_stock tidak mencukupi
     * @throws InventoryException
     */
    public function recordOutbound(
        Product $product,
        int $quantity,
        ?User $user = null,
        ?string $referenceCode = null,
        ?CarbonInterface $movementDate = null,
    ): InventoryMovement {
        if ($quantity <= 0) {
            throw InventoryException::invalidQuantity('Outbound');
        }

        return $this->applyMovement(
            product: $product,
            type: MovementType::Outbound,
            deltaResolver: fn (): int => -$quantity,
            user: $user,
            supplierId: null,
            referenceCode: $referenceCode,
            movementDate: $movementDate,
        );
    }

    /**
     * Stock Opname: sinkronkan angka sistem dengan fisik aktual gudang.
     *
     * Parameter $physicalCount adalah angka fisik hasil hitung, BUKAN delta.
     * Service menghitung selisihnya terhadap stok sistem di dalam transaksi,
     * lalu menyimpan delta tersebut di buku besar.
     *
     * Selisih nol tetap dicatat (artinya fisik cocok dengan sistem) karena
     * jejak audit untuk stock opname itu sendiri bernilai.
     *
     * @throws InventoryException bila $physicalCount negatif
     */
    public function recordStockOpname(
        Product $product,
        int $physicalCount,
        ?User $user = null,
        ?string $referenceCode = null,
        ?CarbonInterface $movementDate = null,
    ): InventoryMovement {
        if ($physicalCount < 0) {
            throw InventoryException::negativePhysicalCount();
        }

        return $this->applyMovement(
            product: $product,
            type: MovementType::Adjustment,
            deltaResolver: fn (Product $locked): int => $physicalCount - $locked->current_stock,
            user: $user,
            supplierId: null,
            referenceCode: $referenceCode,
            movementDate: $movementDate,
        );
    }

    // -----------------------------------------------------------------
    // Inti: satu transaksi untuk stok + buku besar + audit
    // -----------------------------------------------------------------

    /**
     * @param  Closure(Product): int  $deltaResolver  menghitung delta dari stok
     *                                                yang sudah terkunci
     * @param  Closure(InventoryMovement, Product): void|null  $afterSave  hook setelah stok tersimpan
     */
    private function applyMovement(
        Product $product,
        MovementType $type,
        Closure $deltaResolver,
        ?User $user,
        ?int $supplierId,
        ?string $referenceCode,
        ?CarbonInterface $movementDate,
        ?Closure $afterSave = null,
    ): InventoryMovement {
        return DB::transaction(function () use ($product, $type, $deltaResolver, $user, $supplierId, $referenceCode, $movementDate, $afterSave) {
            // Kunci baris produk (SELECT ... FOR UPDATE) supaya dua operator yang
            // bergerak bersamaan tidak saling menimpa current_stock.
            // Karena global scope SoftDeletes, produk yang sudah di-soft-delete
            // tidak akan muncul di query ini, jadi null berarti tidak ada / arsip.
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw InventoryException::productUnavailable((int) $product->getKey());
            }

            $stockBefore = $locked->current_stock;
            $delta = $deltaResolver($locked);

            // Pertahanan berlapis: selisih apa pun tidak boleh membuat stok negatif.
            // Untuk adjustment hal ini mustahil terjadi karena $physicalCount sudah
            // divalidasi >= 0, tapi tetap dijaga agar aturan ini tidak bisa dilanggar.
            if ($stockBefore + $delta < 0) {
                throw new InsufficientStockException($locked->id, abs($delta), $stockBefore);
            }

            $movement = InventoryMovement::create([
                'product_id' => $locked->id,
                'user_id' => $user?->id,
                'supplier_id' => $supplierId,
                'movement_type' => $type,
                'quantity' => $delta,
                'reference_code' => $referenceCode,
                'movement_date' => $movementDate ?? now(),
            ]);

            $locked->current_stock = $stockBefore + $delta;
            $locked->save();

            $this->writeAuditLog($movement, $locked, $type, $stockBefore, $user);

            if ($afterSave !== null) {
                $afterSave($movement, $locked);
            }

            $this->triggerLowStockAlertIfNeeded($locked, $movement);

            return $movement->setRelation('product', $locked);
        }, 3); // 3x percobaan bila terjadi deadlock
    }

    // -----------------------------------------------------------------
    // Efek samping
    // -----------------------------------------------------------------

    /**
     * Jejak rekam aktivitas (PRD bagian 3: Audit Sistem).
     */
    private function writeAuditLog(
        InventoryMovement $movement,
        Product $product,
        MovementType $type,
        int $stockBefore,
        ?User $user,
    ): void {
        $reference = $movement->reference_code ? " ref {$movement->reference_code}" : '';

        AuditLog::create([
            'user_id' => $user?->id,
            'action' => self::AUDIT_PREFIX[$type->value].'.create',
            'description' => sprintf(
                '%s [%s] %+d%s -> stok %d menjadi %d oleh %s',
                $type->label(),
                $product->sku,
                $movement->quantity,
                $reference,
                $stockBefore,
                $product->current_stock,
                $user?->name ?? 'sistem',
            ),
        ]);
    }

    /**
     * Peringatan Cerdas (UseCase: "Kirim Peringatan Stok Menipis").
     *
     * Hanya dipicu oleh aktivitas pengeluaran barang, sesuai PRD bagian 3:
     * "Jika aktivitas pengeluaran barang mengakibatkan sisa stok mencapai atau
     * berada di bawah batas aman, sistem memicu Kirim Peringatan Stok Menipis."
     */
    private function triggerLowStockAlertIfNeeded(Product $product, InventoryMovement $movement): void
    {
        if ($movement->movement_type !== MovementType::Outbound) {
            return;
        }

        if (! $product->needsReorder()) {
            return;
        }

        $message = sprintf(
            'Stok menipis: %s (%s) tersisa %d, batas aman %d. Kurang %d unit.',
            $product->name,
            $product->sku,
            $product->current_stock,
            $product->reorder_point,
            max(0, $product->reorder_point - $product->current_stock),
        );

        AuditLog::create([
            'user_id' => $movement->user_id,
            'action' => 'alert.low_stock',
            'description' => $message,
        ]);

        Log::warning('Smart-Stock: peringatan stok menipis', [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'current_stock' => $product->current_stock,
            'reorder_point' => $product->reorder_point,
        ]);
    }

    /**
     * Simpan harga beli terakhir per pemasok (PRD Modul 1). Bila pemasok belum
     * pernah terhubung ke produk ini, relasi pivot-nya dibuat sekarang.
     */
    private function recordPurchasePrice(Product $product, int $supplierId, float $unitPrice): void
    {
        $pivotAttributes = ['last_purchase_price' => $unitPrice];

        if ($product->suppliers()->where('suppliers.id', $supplierId)->exists()) {
            $product->suppliers()->updateExistingPivot($supplierId, $pivotAttributes);
        } else {
            $product->suppliers()->attach($supplierId, $pivotAttributes);
        }
    }
}
