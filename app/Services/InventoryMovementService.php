<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InventoryException;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Support\LowStockAlert;
use App\Services\Support\MovementLine;
use Carbon\CarbonInterface;
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
 * Beserta peringatan stok menipis (UseCase: "Kirim Peringatan Stok Menipis").
 * Controller tidak boleh mengubah stok langsung; semuanya lewat kelas ini.
 *
 * Konvensi delta: inventory_movements.quantity menyimpan selisih bertanda,
 * sehingga SUM(quantity) per produk selalu sama dengan current_stock.
 */
final class InventoryMovementService
{
    public function __construct(
        private readonly ProductSupplierService $productSuppliers = new ProductSupplierService,
        private readonly LowStockAlertService $lowStockAlerts = new LowStockAlertService,
    ) {}

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
     * @param  int|null  $supplierId  pemasok (referensi faktur), opsional.
     *                                Bila diisi, pemasok otomatis ditautkan ke
     *                                produk (lihat catatan di bawah).
     * @param  string|null  $referenceCode  nomor faktur / dokumen
     * @param  CarbonInterface|null  $movementDate  tanggal transaksi, default sekarang
     * @param  float|null  $unitPrice  harga beli satuan; bila diberikan, nilai ini
     *                                 juga memperbarui `last_purchase_price` di
     *                                 pemetaan produk-pemasok (PRD Modul 1)
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

        $document = $this->recordDocument(
            type: MovementType::Inbound,
            lines: [['product' => $product, 'quantity' => $quantity, 'unit_price' => $unitPrice]],
            user: $user,
            supplierId: $supplierId,
            physicalReference: $referenceCode,
            transactionDate: $movementDate,
        );

        return $document->movements->first();
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

        $document = $this->recordDocument(
            type: MovementType::Outbound,
            lines: [['product' => $product, 'quantity' => $quantity]],
            user: $user,
            supplierId: null,
            physicalReference: $referenceCode,
            transactionDate: $movementDate,
        );

        return $document->movements->first();
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

        $document = $this->recordDocument(
            type: MovementType::Adjustment,
            lines: [['product' => $product, 'physical_count' => $physicalCount]],
            user: $user,
            supplierId: null,
            physicalReference: $referenceCode,
            transactionDate: $movementDate,
        );

        return $document->movements->first();
    }

    // -----------------------------------------------------------------
    // Inti: satu dokumen, banyak baris, satu transaksi DB
    // -----------------------------------------------------------------

    /**
     * Catat satu DOKUMEN pergerakan beserta seluruh baris itemnya.
     *
     * Ini jalur utama sejak pemecahan header-detail. Ketiga method lama
     * (`recordInbound`, `recordOutbound`, `recordStockOpname`) memanggil
     *method ini dengan satu baris, jadi tidak ada implementasi ganda.
     *
     * ATURAN TRANSAKSI: satu dokumen bersifat UTUH. Kalau baris ke-8 tidak
     * punya stok cukup, dokumen SELURUHNYA batal dan tidak ada satu pun baris
     * yang tersimpan. Ini yang benar untuk penerimaan barang: purchase order yang
     * separuh diterima dicatat sebagai penerimaan sebagian, bukan sebagai
     * dokumen utuh yang gagal.
     *
     * URUTAN PENGUNCIAN: baris diurutkan berdasarkan product_id SEBELUM
     * dikunci. Tanpa itu, dua dokumen yang sama-sama memuat produk A dan B
     * bisa saling mengunci dalam urutan berlawanan lalu deadlock. Mengurutkan
     * membuat semua dokumen mengunci dalam urutan yang sama.
     *
     * @param  array<int, array<string, mixed>>  $lines  tiap baris punya kunci
     *                                                   `product`, lalu
     *                                                   `quantity`
     *                                                   (Inbound/Outbound)
     *                                                   atau
     *                                                   `physical_count`
     *                                                   (Stock Opname),
     *                                                   opsional
     *                                                   `unit_price`
     *
     * @throws InsufficientStockException bila ada baris yang stoknya tidak cukup
     * @throws InventoryException
     */
    public function recordDocument(
        MovementType $type,
        array $lines,
        ?User $user = null,
        ?int $supplierId = null,
        ?string $physicalReference = null,
        ?CarbonInterface $transactionDate = null,
    ): InventoryTransaction {
        $baris = $this->siapkanBaris($type, $lines);

        // Dikumpulkan di dalam transaksi, dikirim setelah selesai.
        $pendingAlerts = [];

        $document = DB::transaction(function () use (
            $type, $baris, $user, $supplierId, $physicalReference, $transactionDate, &$pendingAlerts
        ): InventoryTransaction {
            $document = InventoryTransaction::create([
                'system_code' => InventoryTransaction::nextSystemCode(
                    $type,
                    $transactionDate?->toDateString() ?? now()->toDateString(),
                ),
                'physical_reference' => $physicalReference,
                'movement_type' => $type,
                'supplier_id' => $supplierId,
                'user_id' => $user?->id,
                'transaction_date' => $transactionDate ?? now(),
            ]);

            foreach ($baris as $line) {
                // Kunci baris produk (SELECT ... FOR UPDATE) supaya dua operator
                // yang bergerak bersamaan tidak saling menimpa current_stock.
                // Karena global scope SoftDeletes, produk yang sudah di-soft-delete
                // tidak muncul di query ini, jadi null berarti tidak ada / arsip.
                $locked = Product::query()->whereKey($line->productId)->lockForUpdate()->first();

                if ($locked === null) {
                    throw InventoryException::productUnavailable($line->productId);
                }

                $stockBefore = $locked->current_stock;

                $delta = match ($type) {
                    MovementType::Inbound => $line->quantity,
                    MovementType::Outbound => -$line->quantity,
                    // Stock opname menyimpan ANGKA FISIK di form; yang masuk ke
                    // buku besar adalah selisihnya terhadap stok sistem.
                    MovementType::Adjustment => (int) $line->physicalCount - $stockBefore,
                };

                // Pertahanan berlapis: selisih apa pun tidak boleh membuat stok
                // negatif. Untuk adjustment hal ini mustahil terjadi karena
                // physicalCount sudah divalidasi >= 0, tapi tetap dijaga.
                if ($stockBefore + $delta < 0) {
                    throw new InsufficientStockException($locked->id, abs($delta), $stockBefore);
                }

                $movement = InventoryMovement::create([
                    // Tahap 4: baris detail hanya berisi apa yang benar-benar
                    // milik baris. `movement_type`, `user_id`, dan `supplier_id`
                    // TIDAK lagi ditulis di sini — kolomnya sudah dihapus.
                    // Semuanya sudah ada di `$document` di atas.
                    'transaction_id' => $document->id,
                    'product_id' => $locked->id,

                    'quantity' => $delta,
                    'unit_price' => $line->unitPrice,
                    'reference_code' => $physicalReference,
                    'movement_date' => $transactionDate ?? now(),
                ]);

                $locked->current_stock = $stockBefore + $delta;
                $locked->save();

                $movement->setRelation('transaction', $document);
                $movement->setRelation('product', $locked);

                $this->writeAuditLog($movement, $locked, $type, $stockBefore, $user, $document);

                // Pemetaan produk-pemasok: hubungan "pemasok ini menyuplai
                // produk ini" adalah fakta historis, jadi harus terbentuk dari
                // pencatatan barang, bukan menunggu admin mengisinya manual.
                if ($type === MovementType::Inbound && $supplierId !== null) {
                    $this->linkSupplier($locked, $supplierId, $line->unitPrice, $user);
                }

                $alert = $this->triggerLowStockAlertIfNeeded($locked, $movement, $document);

                if ($alert !== null) {
                    $pendingAlerts[] = $alert;
                }
            }

            return $document;
        }, 3); // 3x percobaan bila terjadi deadlock

        // Notifikasi dikirim DI LUAR transaksi. `afterCommit()` pada notifikasi
        // Laravel hanya berlaku untuk notifikasi berantrean (ShouldQueue),
        // bukan channel `database` yang dipakai di sini. Kegagalan notifikasi
        // tidak boleh membatalkan perubahan stok yang sah.
        foreach ($pendingAlerts as $alert) {
            $this->lowStockAlerts->dispatch($alert);
        }

        return $document->load('movements.product');
    }

    /**
     * Validasi + urutkan baris sebelum diproses.
     *
     * Pengurutan berdasarkan product_id bukan hiasan: lihat catatan urutan penguncian di
     * `recordDocument()`.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, MovementLine>
     */
    private function siapkanBaris(MovementType $type, array $lines): array
    {
        if ($lines === []) {
            throw InventoryException::invalidQuantity($type->label());
        }

        $baris = MovementLine::dariBanyak($lines);

        foreach ($baris as $line) {
            if ($type === MovementType::Adjustment) {
                if ($line->physicalCount === null || $line->physicalCount < 0) {
                    throw InventoryException::negativePhysicalCount();
                }
            } elseif ($line->quantity <= 0) {
                throw InventoryException::invalidQuantity($type->label());
            }

            if ($line->unitPrice !== null && $line->unitPrice < 0) {
                throw InventoryException::unitPriceNegative($line->unitPrice);
            }
        }

        usort($baris, fn (MovementLine $a, MovementLine $b): int => $a->productId <=> $b->productId);

        return $baris;
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
        InventoryTransaction $document,
    ): void {
        $reference = $movement->reference_code ? " ref {$movement->reference_code}" : '';

        // Untuk barang masuk, sebutkan pemasoknya. Tanpa ini auditor hanya
        // melihat "Inbound [ELK-001] +131 ref PO-... -> stok 109 menjadi 240"
        // tanpa tahu barang itu datang dari mana.
        //
        // Pemasok dibaca dari DOKUMEN, bukan dari baris detail — itu milik
        // faktur, bukan milik tiap baris.
        //
        // Hanya ditampilkan bila ada. Stok pembuka sering memang tidak punya
        // dokumen pembelian, dan kosong di sini justru informasi yang berguna:
        // berarti asal-usulnya perlu ditelusuri manual.
        $supplier = $document->supplier;

        $fromSupplier = $supplier !== null
            ? sprintf(' dari %s', $supplier->name)
            : '';

        AuditLog::create([
            'user_id' => $user?->id,
            'action' => self::AUDIT_PREFIX[$type->value].'.create',
            'description' => sprintf(
                '%s [%s] %+d%s%s -> stok %d menjadi %d oleh %s',
                $type->label(),
                $product->sku,
                $movement->quantity,
                $reference,
                $fromSupplier,
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
     *
     * Yang ditulis di dalam transaksi hanyalah jejak audit + log aplikasi.
     * Notifikasi ke pengguna dikirim SETELAH transaksi selesai oleh
     * `dispatchPendingAlert()`, supaya kegagalan notifikasi tidak membatalkan
     * perubahan stok yang sah.
     */
    private function triggerLowStockAlertIfNeeded(
        Product $product,
        InventoryMovement $movement,
        InventoryTransaction $document,
    ): ?LowStockAlert {
        // Jenis dibaca dari dokumen, bukan dari kolom di baris ini.
        if ($document->movement_type !== MovementType::Outbound) {
            return null;
        }

        if (! $product->needsReorder()) {
            return null;
        }

        $alert = new LowStockAlert(
            productId: (int) $product->id,
            sku: $product->sku,
            productName: $product->name,
            currentStock: (int) $product->current_stock,
            reorderPoint: (int) $product->reorder_point,
            shortfall: max(0, (int) $product->reorder_point - (int) $product->current_stock),
        );

        AuditLog::create([
            'user_id' => $document->user_id,
            'action' => 'alert.low_stock',
            // Awalan "Stok menipis: " dipertahankan karena audit_logs adalah
            // record historis yang formatnya sudah dikunci test. Pesan notifikasi
            // memakai $alert->message() yang lebih ringkas untuk UI.
            'description' => "Stok menipis: {$alert->message()}",
        ]);

        Log::warning('SmartStock: peringatan stok menipis', [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'current_stock' => $product->current_stock,
            'reorder_point' => $product->reorder_point,
        ]);

        return $alert;
    }

    /**
     * Kirim peringatan ke kanal nyata, di luar transaksi.
     *
     * `afterCommit()` pada notifikasi Laravel hanya berlaku untuk notifikasi
     * berantrean, bukan channel `database` — jadi pengiriman dilakukan
     * eksplisit di titik ini, dan seluruhnya dibungkus try/catch.
     */
    private function dispatchPendingAlert(?LowStockAlert $alert): void
    {
        if ($alert === null) {
            return;
        }

        try {
            $this->lowStockAlerts->dispatch($alert);
        } catch (\Throwable $e) {
            Log::error('SmartStock: pengiriman peringatan stok menipis gagal', [
                'product_id' => $alert->productId,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Tautkan pemasok ke produk, dan perbarui harga beli bila ada.
     *
     * Dua langkah yang harus berurutan:
     *  1. `ensureLinked()` membuat tautan kalau belum ada, tanpa menimpa
     *     harga / lead time yang sudah tercatat.
     *  2. Kalau inbound membawa harga, `recordPurchasePrice()` memperbarui
     *     kolom harga saja. Lead time tetap kosong karena itu hasil
     *     kesepakatan yang tidak bisa disimpulkan dari satu transaksi.
     */
    private function linkSupplier(Product $product, int $supplierId, ?float $unitPrice, ?User $user): void
    {
        $supplier = Supplier::find($supplierId);

        if ($supplier === null) {
            return;
        }

        $this->productSuppliers->ensureLinked($product, $supplier, $user);

        if ($unitPrice !== null) {
            $this->productSuppliers->recordPurchasePrice($product, $supplier, $unitPrice);
        }
    }
}
