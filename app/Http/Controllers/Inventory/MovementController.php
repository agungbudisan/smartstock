<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMovementRequest;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\InventoryMovementService;
use App\Services\ProductSupplierService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operasional inventaris untuk Admin Gudang (PRD Modul 2).
 *
 * Controller ini TIDAK mengubah stok sendiri — semuanya lewat
 * InventoryMovementService supaya aturan transaksi dan audit tidak terduplikasi.
 */
class MovementController extends Controller
{
    public function __construct(
        private readonly InventoryMovementService $movementService,
        private readonly ProductSupplierService $productSuppliers,
    ) {}

    /**
     * Halaman entri pergerakan stok. KHUSUS Admin Gudang.
     *
     * Route-nya dibatasi `role:admin_gudang` supaya halaman yang menampilkan
     * form entri ini tidak pernah tampil untuk Manager. Manager memakai
     * `ledger()`.
     *
     * Alasannya bukan sekadar keamanan: form yang tidak bisa disimpan adalah
     * jebakan. Operator mengisi dengan benar lalu menekan Simpan dan mendapat 403
     * tanpa penjelasan -- dan halaman ini memang dalam keadaan itu sebelum
     * RBAC-nya diperbaiki.
     *
     * Buku besar TIDAK ikut dikirim ke sini. Halamannya sudah ada sendiri di
     * `ledger()`, lengkap dengan filter yang bisa dipakai; di bawah form dulu ia
     * selalu menampilkan 20 baris terbaru apa adanya. Mengirim data yang tidak
     * dirender hanya menambah ukuran props tanpa menambah kemampuan.
     *
     * Karena tidak ada tabel lagi di halaman ini, `Request` hanya dipakai untuk
     * old input: tidak ada query buku besar, filter, maupun paginasi.
     */
    public function index(Request $request): Response
    {
        $products = Product::orderBy('name')->get(['id', 'sku', 'name', 'current_stock']);

        return Inertia::render('Inventory/Movements', [
            'oldMovement' => $this->entriLama($request),
            'products' => $products,
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),

            // Peta { productId: [{id, name}, ...] } supaya dropdown pemasok di
            // form Inbound bisa menyaring sesuai produk terpilih tanpa request
            // tambahan. Semua pemasok tetap dikirim di atas sebagai opsi
            // "belum terdaftar" — filter, bukan pagar, supaya admin tetap bisa
            // mencatat barang dari pemasok yang baru.
            'suppliersByProduct' => $this->productSuppliers->suppliersByProduct(
                $products->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            ),
            'movementTypes' => $this->movementTypes(),
        ]);
    }

    /**
     * Buku Besar Stok sebagai halaman mandiri. Boleh dibuka kedua peran.
     *
     * Halaman ini TIDAK menampilkan form apa pun. Manager boleh membaca ledger
     * karena ledger adalah jejak audit -- tanpa itu, pertanyaan "stok ini naik 40
     * tanpa ada PO, kenapa?" tidak punya jawaban. Tapi ia tidak boleh mencatat
     * pergerakan, dan menampilkan form yang tidak bisa disimpan hanya menambah
     * langkah sia-sia sebelum 403.
     *
     * Halaman ini satu-satunya pemanggil `filterLedger()`, dan sengaja tidak
     * memakai old input: old input berasal dari form entri, sedangkan halaman ini
     * tidak punya form. Menggabungkannya akan membuat filter ikut berubah diam-diam
     * setelah percobaan simpan yang gagal di halaman lain.
     */
    public function ledger(Request $request): Response
    {
        $filters = $this->filterLedger($request);

        return Inertia::render('Inventory/Ledger', [
            'movements' => $this->bukuBesar($filters),
            'filters' => $filters,
            'products' => Product::orderBy('name')->get(['id', 'sku', 'name']),
            'movementTypes' => $this->movementTypes(),
        ]);
    }

    /**
     * Validasi dan bersihkan filter buku besar.
     *
     * Parameter `$withOldInput` sudah dihapus. Dulu halaman entri ikut memakai
     * filter ini supaya jenis dan tanggal bisa diambil dari old input, karena
     * saat itu buku besar masih ikut tampil di halaman entri. Sekarang
     * yang tidak punya form sama sekali, jadi mencampur old input ke filter
     * hanya akan membuat daftar ikut berubah diam-diam setelah percobaan simpan
     * yang gagal di halaman lain.
     *
     * @return array<string, mixed>
     */
    private function filterLedger(Request $request): array
    {
        $filters = $request->validate([
            'product_id' => ['nullable', 'integer'],
            'movement_type' => ['nullable', 'string'],
            'movement_date' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        // Null dibuang, bukan dibiarkan sebagai kunci bernilai null: nilai itu
        // bukan filter, dan `Inertia` mengirimkannya apa adanya ke frontend.
        return array_filter($filters, fn ($value): bool => $value !== null);
    }

    /**
     * Query buku besar + pemetaan baris untuk Inertia.
     *
     * Identitas dokumen (jenis, pemasok, pencatat) dibaca dari header. Relasi
     * di-eager-load lewat `transaction.supplier` supaya tabel Buku Besar tidak
     * jadi N+1.
     *
     * @param  array<string, mixed>  $filters
     */
    private function bukuBesar(array $filters): LengthAwarePaginator
    {
        return InventoryMovement::query()
            ->with([
                'product:id,sku,name',
                'transaction:id,movement_type,supplier_id,user_id,system_code,physical_reference',
                'transaction.supplier:id,name',
                'transaction.user:id,name',
            ])
            ->when($filters['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->when(
                $filters['movement_type'] ?? null,
                fn ($query, $type) => $query->ofMovementType(MovementType::from($type)),
            )
            ->when($filters['movement_date'] ?? null, fn ($query, $date) => $query->whereDate('movement_date', $date))
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString()
            ->through(function (InventoryMovement $movement): array {
                $document = $movement->transaction;

                return [
                    'id' => $movement->id,
                    'movement_type' => $document?->movement_type->value,
                    'movement_type_label' => $document?->movement_type->label(),
                    'quantity' => $movement->quantity,
                    'reference_code' => $document?->physical_reference,
                    'system_code' => $document?->system_code,
                    'movement_date' => $movement->movement_date->toIso8601String(),
                    'product' => $movement->product?->only(['id', 'sku', 'name']),
                    'user' => $document?->user?->only(['id', 'name']),
                    'supplier' => $document?->supplier?->only(['id', 'name']),
                    // Baris lain dari faktur yang sama. Buku Besar menampilkan
                    // ini supaya jelas satu dokumen bisa menutup banyak SKU --
                    // tanpa itu, nomor faktur yang sama muncul N kali dan terlihat
                    // seperti duplikasi data.
                    'sibling_count' => $document === null ? 1 : max(1, $document->movements()->count()),
                ];
            });
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function movementTypes(): array
    {
        return array_map(
            fn (MovementType $type) => ['value' => $type->value, 'label' => $type->label()],
            MovementType::cases(),
        );
    }

    /**
     * Entri terakhir yang gagal validasi, supaya form tidak "lupa" apa yang
     * sudah diketik.
     *
     * Tanpa ini, redirect `back()` setelah validasi gagal akan merender ulang
     * komponen dengan state default: mode kembali ke Outbound dan pilihan
     * produk/pemasok/harga yang sudah diisi hilang. Operator kemudian mengira
     * datanya tidak tersimpan dan mengetik ulang dari awal.
     *
     * Field yang dikirim ulang diambil satu per satu, bukan `$request->all()`,
     * supaya halaman publik ini tidak pernah membocorkan input halaman lain
     * (mis. password dari form login) ke props Inertia.
     *
     * @return array<string, mixed>
     */
    private function entriLama(Request $request): array
    {
        $kolom = [
            'movement_type',
            'supplier_id',
            'reference_code',

            // `tanggal` + `waktu`, bukan `movement_date`: latter sudah
            // digabung oleh StoreMovementRequest::prepareForValidation()
            // dan bentuknya 'Y-m-d H:i:s', sedangkan `<input type="date">`
            // dan `<input type="time">` butuh 'Y-m-d' dan 'H:i'.
            'tanggal',
            'waktu',
        ];

        $entri = [];

        foreach ($kolom as $nama) {
            $nilai = $request->old($nama);

            if ($nilai !== null && $nilai !== '') {
                $entri[$nama] = $nilai;
            }
        }

        // Baris item dipulihkan satu per satu, bukan utuh, supaya baris yang
        // tidak ada isinya tidak muncul sebagai baris kosong menumpuk di form.
        $items = $request->old('items');

        if (is_array($items)) {
            $bersih = [];

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $baris = [];

                foreach (['product_id', 'quantity', 'physical_count', 'unit_price'] as $kunci) {
                    $nilai = $item[$kunci] ?? null;

                    if ($nilai !== null && $nilai !== '') {
                        $baris[$kunci] = $nilai;
                    }
                }

                if ($baris !== []) {
                    $bersih[] = $baris;
                }
            }

            if ($bersih !== []) {
                $entri['items'] = $bersih;
            }
        }

        return $entri;
    }

    /**
     * Simpan satu DOKUMEN pergerakan beserta seluruh baris itemnya.
     *
     * Satu request = satu faktur. Ini yang berubah di tahap 3: sebelumnya
     * satu request hanya bisa satu produk, jadi purchase order berisi 12 SKU
     * harus disimpan dua belas kali -- dan tiap sekali jadi dokumen terpisah
     * dengan nomor yang sama, sehingga tidak ada yang bisa bilang "ini satu
     * faktur".
     */
    public function store(StoreMovementRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $type = MovementType::from($validated['movement_type']);

        // Aturan `date` hanya memastikan formatnya benar, TIDAK mengubah
        // nilainya jadi objek tanggal -- yang keluar dari `validated()` masih
        // string. Service movement menerima `?Carbon\CarbonInterface`, jadi
        // string harus diubah dulu di sini; kalau tidak, request berakhir 500
        // dengan TypeError.
        //
        // `Carbon::parse()` aman di sini karena `date` sudah menyaring nilai
        // yang tidak bisa di-parse. Nilai kosong tidak pernah sampai: middleware
        // `ConvertEmptyStringsToNull` mengubahnya jadi null dan aturannya
        // `nullable`, jadi tidak lolos ke `validated()`.
        $transactionDate = isset($validated['movement_date'])
            ? Carbon::parse($validated['movement_date'])
            : null;

        $lines = $this->susunBaris($validated['items'], $type);

        try {
            $document = $this->movementService->recordDocument(
                type: $type,
                lines: $lines,
                user: $request->user(),
                supplierId: $validated['supplier_id'] ?? null,
                physicalReference: $validated['reference_code'] ?? null,
                transactionDate: $transactionDate,
            );
        } catch (InsufficientStockException $exception) {
            // Balas sebagai error validasi, bukan 500. Field-nya disamarkan
            // sebagai `items.0.*` supaya baris pertama yang punya kolom masih
            // bisa menampilkannya; service sudah mengecek stok per baris, jadi
            // pesan detail per produk tiba lewat `StoreMovementRequest`.
            return back()
                ->withInput()
                ->withErrors(['items' => $exception->getMessage()]);
        }

        $jumlahBaris = $document->movements()->count();
        $kode = $document->displayReference();

        $message = match ($type) {
            MovementType::Inbound => "Barang masuk dicatat di {$kode}. {$jumlahBaris} baris tersimpan.",
            MovementType::Outbound => "Barang keluar dicatat di {$kode}. {$jumlahBaris} baris tersimpan.",
            MovementType::Adjustment => "Stock opname dicatat di {$kode}. {$jumlahBaris} baris tersimpan.",
        };

        // Peringatan stok menipis ikut terasa di antarmuka, bukan hanya di log.
        //
        // `movements()` mengembalikan relasi HasMany, bukan Collection, jadi
        // harus di-`get()` dulu sebelum bisa di-filter.
        $perluPemesanan = $document->movements()
            ->with('product')
            ->get()
            ->filter(fn (InventoryMovement $movement): bool => $movement->product?->needsReorder() ?? false)
            ->map(fn (InventoryMovement $movement): string => $movement->product->sku)
            ->unique()
            ->values();

        if ($perluPemesanan->isNotEmpty()) {
            Log::debug('Peringatan stok menipis dikirim ke antarmuka', ['skus' => $perluPemesanan->all()]);

            return back()->with('success', $message)
                ->with('warning', sprintf(
                    'Stok sudah di bawah batas aman: %s. Pertimbangkan pemesanan ulang.',
                    $perluPemesanan->implode(', '),
                ));
        }

        return back()->with('success', $message);
    }

    /**
     * Ubah array `items` dari form menjadi baris yang siap diolah service.
     *
     * Modelnya diambil dalam satu query, bukan satu per baris: faktur 12 baris
     * akan memicu 12 kueri produk kalau diambil satu per satu.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function susunBaris(array $items, MovementType $type): array
    {
        $ids = array_values(array_unique(array_map(
            fn (array $item): int => (int) ($item['product_id'] ?? 0),
            $items,
        )));

        $produks = Product::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach ($items as $item) {
            $produk = $produks->get((int) ($item['product_id'] ?? 0));

            if ($produk === null) {
                // Sudah tertangkap aturan `items.*.product_id`; dilewati di
                // sini supaya tidak meledak jadi 500.
                continue;
            }

            $baris = ['product' => $produk];

            if ($type === MovementType::Adjustment) {
                $baris['physical_count'] = (int) ($item['physical_count'] ?? 0);
            } else {
                $baris['quantity'] = (int) ($item['quantity'] ?? 0);
            }

            if (isset($item['unit_price']) && $item['unit_price'] !== '') {
                $baris['unit_price'] = (float) $item['unit_price'];
            }

            $lines[] = $baris;
        }

        return $lines;
    }
}
