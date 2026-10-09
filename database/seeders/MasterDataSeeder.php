<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryMovementService;
use App\Services\ProductSupplierService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Master data demo: pengguna, kategori, pemasok, produk, dan pemetaan
 * harga beli + lead time.
 *
 * Sengaja ditulis eksplisit (bukan lewat factory) supaya hasil seeding
 * konsisten dan bisa dibaca sebagai data contoh yang masuk akal.
 */
class MasterDataSeeder extends Seeder
{
    /** @var array<string, User> */
    public array $users = [];

    /** Termasuk akun belum verifikasi, yang tidak masuk $users. */
    public int $jumlahPengguna = 0;

    /** @var array<string, Category> */
    public array $categories = [];

    /** @var array<string, Supplier> */
    public array $suppliers = [];

    /** @var Collection<int, Product> */
    public Collection $products;

    /**
     * Profil permintaan per produk, dipakai MovementHistorySeeder untuk
     * membangkitkan riwayat outbound yang terlihat wajar.
     *
     * @var array<int, array{baseline: float, trend: float, active_rate: float, opening_stock: int}>
     */
    public array $demandProfiles = [];

    public function run(): void
    {
        $this->seedUsers();
        $this->seedCategories();
        $this->seedSuppliers();
        $this->seedProducts();

        $this->command?->info(sprintf(
            '  Master data: %d pengguna, %d kategori, %d pemasok, %d produk',
            $this->jumlahPengguna,
            count($this->categories),
            count($this->suppliers),
            $this->products->count(),
        ));
    }

    // -----------------------------------------------------------------

    private function seedUsers(): void
    {
        $this->users['admin'] = User::factory()->adminGudang()->create([
            'name' => 'Budi Santoso',
            'email' => 'admin@smartstock.test',
        ]);

        $this->users['admin_operators'] = User::factory()->adminGudang()->create([
            'name' => 'Siti Rahayu',
            'email' => 'operator@smartstock.test',
        ]);

        $this->users['manager'] = User::factory()->managerBisnis()->create([
            'name' => 'Dewi Lestari',
            'email' => 'manager@smartstock.test',
        ]);

        // Akun belum verifikasi — sengaja disimpan agar halaman
        // "Verifikasi Email" bisa dicoba, dan agar tidak menerima notifikasi.
        User::factory()->unverified()->adminGudang()->create([
            'name' => 'Andi_pending',
            'email' => 'pending@smartstock.test',
        ]);

        $this->jumlahPengguna = count($this->users) + 1;
    }

    private function seedCategories(): void
    {
        // Prefix SKU ditulis eksplisit, bukan diturunkan dari nama kategori.
        //
        // "Alat Tulis" menjadi ATS, bukan AT -- itu keputusan penamaan yang
        // disengaja, dan tidak ada aturan yang bisa menghasilkan dari nama.
        // Itu sebabnya `categories.sku_prefix` ada sebagai kolom: kalau dihitung
        // otomatis, saran SKU akan meleset tepat di kategori yang paling tidak
        // bisa ditebak.
        //
        // Nilainya sengaja SAMA dengan awalan SKU di `seedProducts()` di bawah,
        // supaya data demo benar-benar mengikuti pola yang diajarkan form.
        foreach ([
            'Elektronik' => ['Perangkat dan aksesori elektronik', 'ELK'],
            'Sembako' => ['Kebutuhan pokok rumah tangga', 'SEM'],
            'Kebersihan' => ['Perlengkapan kebersihan', 'KEB'],
            'Alat Tulis' => ['Perlengkapan kantor dan sekolah', 'ATS'],
            'Perawatan' => ['Kebutuhan perawatan kendaraan dan mesin', 'PER'],
        ] as $nama => [$deskripsi, $prefix]) {
            $this->categories[$nama] = Category::factory()->create([
                'name' => $nama,
                'sku_prefix' => $prefix,
                'description' => $deskripsi,
            ]);
        }
    }

    private function seedSuppliers(): void
    {
        $data = [
            'nusantara' => ['PT Sumber Nusantara', 'Andi Prasetyo', '0812-1100-2201', 'Jl. Raya Bogor KM 12, Cimanggis'],
            'mitra' => ['CV Mitra Gudang Sejahtera', 'Ratna Dewi', '0813-2200-3311', 'Jl. Industri Raya Blok C, Bekasi'],
            'baja' => ['PT Baja Permai', 'Hendra Wijaya', '0821-3300-4412', 'Jl. Raya Industri 88, Surabaya'],
            'pangan' => ['UD Sumber Pangan Nusantara', 'Yusuf Maulana', '0857-4400-5513', 'Pasar Induk Kramat Jati, Blok B2'],
            'logistik' => ['PT Logistik Timur Jaya', 'Rina Kusuma', '0819-5500-6614', 'Jl. Raya Surabaya 88, Gedangan'],
            'bersih' => ['CV Bersih Higienis', 'Tono Halim', '0852-6600-7715', 'Jl. Kemayoran 45, Jakarta Pusat'],
        ];

        foreach ($data as $key => [$nama, $kontak, $telp, $alamat]) {
            $this->suppliers[$key] = Supplier::factory()->create([
                'name' => $nama,
                'contact_person' => $kontak,
                'phone' => $telp,
                'address' => $alamat,
            ]);
        }
    }

    // -----------------------------------------------------------------

    /**
     * Produk beserta pemetaan pemasok dan profil permintaannya.
     *
     * Isi profil:
     *   baseline         rata-rata permintaan per hari saat aktif
     *   trend            kemiringan tren linear dalam 120 hari (-1..1)
     *   active_rate      peluang ada penjualan pada suatu hari (0..1)
     *   opening_stock    penerimaan di hari pertama
     *   restock_interval jeda minimal antar pesanan, dalam hari
     *   target_multiple  stok ditop-up sampai reorder_point × nilai ini
     *
     * Stok akhir TIDAK ditulis di sini — stok dihitung oleh service dari
     * Inbound + Outbound, supaya `SUM(quantity) == current_stock` selalu benar.
     */
    private function seedProducts(): void
    {
        $barang = [
            // sku, nama, kategori, pemasok => [harga beli, lead time], reorder point, profil
            ['ELK-001', 'Kabel Listrik 2 Meter', 'Elektronik', [
                'nusantara' => [15000, 3], 'mitra' => [14200, 7], 'logistik' => [13900, 14],
            ], 60, ['baseline' => 9.0, 'trend' => 0.25, 'active_rate' => 0.72, 'opening_stock' => 260, 'restock_interval' => 12, 'target_multiple' => 3.0]],

            ['ELK-002', 'Lampu LED 12W', 'Elektronik', [
                'nusantara' => [18500, 4], 'mitra' => [17900, 9],
            ], 55, ['baseline' => 6.0, 'trend' => 0.40, 'active_rate' => 0.58, 'opening_stock' => 180, 'restock_interval' => 14, 'target_multiple' => 2.5]],

            ['ELK-003', 'Adaptor USB-C 30W', 'Elektronik', [
                'logistik' => [42000, 10], 'nusantara' => [43500, 5],
            ], 40, ['baseline' => 4.2, 'trend' => 0.55, 'active_rate' => 0.55, 'opening_stock' => 120, 'restock_interval' => 16, 'target_multiple' => 2.5]],

            ['ELK-004', 'Speaker Bluetooth Mini', 'Elektronik', [
                'logistik' => [68000, 18], 'mitra' => [66500, 12],
            ], 25, ['baseline' => 1.6, 'trend' => -0.30, 'active_rate' => 0.32, 'opening_stock' => 64, 'restock_interval' => 20, 'target_multiple' => 3.0]],

            ['SEM-001', 'Beras Premium 5 kg', 'Sembako', [
                'pangan' => [72000, 2], 'nusantara' => [74500, 6],
            ], 120, ['baseline' => 14.0, 'trend' => 0.12, 'active_rate' => 0.80, 'opening_stock' => 420, 'restock_interval' => 10, 'target_multiple' => 2.5]],

            ['SEM-002', 'Minyak Goreng 2 L', 'Sembako', [
                'pangan' => [36000, 2], 'logistik' => [37200, 8],
            ], 90, ['baseline' => 11.0, 'trend' => 0.18, 'active_rate' => 0.76, 'opening_stock' => 340, 'restock_interval' => 11, 'target_multiple' => 2.5]],

            ['SEM-003', 'Gula Pasir 1 kg', 'Sembako', [
                'pangan' => [16500, 2],
            ], 80, ['baseline' => 8.5, 'trend' => -0.10, 'active_rate' => 0.70, 'opening_stock' => 300, 'restock_interval' => 12, 'target_multiple' => 3.0]],

            ['KEB-001', 'Sabun Cuci Piring 800 ml', 'Kebersihan', [
                'bersih' => [12500, 3], 'mitra' => [11900, 8],
            ], 70, ['baseline' => 7.5, 'trend' => 0.08, 'active_rate' => 0.68, 'opening_stock' => 260, 'restock_interval' => 12, 'target_multiple' => 3.0]],

            ['KEB-002', 'Deterjen Koin 1 kg', 'Kebersihan', [
                'bersih' => [19800, 4], 'nusantara' => [20500, 9],
            ], 65, ['baseline' => 5.4, 'trend' => 0.05, 'active_rate' => 0.60, 'opening_stock' => 210, 'restock_interval' => 14, 'target_multiple' => 2.8]],

            ['KEB-003', 'Pembersih Lantai 700 ml', 'Kebersihan', [
                'bersih' => [13900, 3],
            ], 50, ['baseline' => 4.6, 'trend' => 0.02, 'active_rate' => 0.52, 'opening_stock' => 170, 'restock_interval' => 15, 'target_multiple' => 3.0]],

            ['ATS-001', 'Bolpoin Gel 0.5 mm (Isi 12)', 'Alat Tulis', [
                'nusantara' => [22000, 5], 'mitra' => [21500, 11],
            ], 45, ['baseline' => 3.1, 'trend' => -0.45, 'active_rate' => 0.38, 'opening_stock' => 140, 'restock_interval' => 18, 'target_multiple' => 3.0]],

            ['ATS-002', 'Kertas A4 80gr (Rim 500)', 'Alat Tulis', [
                'nusantara' => [58000, 7], 'baja' => [56500, 15],
            ], 35, ['baseline' => 2.2, 'trend' => -0.20, 'active_rate' => 0.42, 'opening_stock' => 95, 'restock_interval' => 18, 'target_multiple' => 3.0]],

            // Dua produk "baru": belum ada riwayat sama sekali. Sengaja,
            // supaya halaman dasbor & akurasi punya kasus tanpa prediksi.
            ['PER-001', 'Oli Mesin 4 Stroke 800 ml', 'Perawatan', [
                'baja' => [32000, 6],
            ], 30, null],

            ['PER-002', 'Suku Cadang Filter Oli', 'Perawatan', [
                'baja' => [15000, 9], 'logistik' => [14700, 16],
            ], 20, null],
        ];

        $mapping = app(ProductSupplierService::class);
        $produk = new Collection;

        foreach ($barang as [$sku, $nama, $kategori, $pemasok, $reorderPoint, $profil]) {
            $product = Product::factory()->create([
                'sku' => $sku,
                'name' => $nama,
                'category_id' => $this->categories[$kategori]->id,
                // Stok awal dikosongkan; MovementHistorySeeder yang mengisi
                // lewat Inbound supaya buku besar tetap konsisten.
                'current_stock' => 0,
                'reorder_point' => $reorderPoint,
            ]);

            foreach ($pemasok as $key => [$harga, $leadTime]) {
                $mapping->attach(
                    product: $product,
                    supplier: $this->suppliers[$key],
                    lastPurchasePrice: $harga,
                    leadTimeDays: $leadTime,
                    user: $this->users['admin'],
                );
            }

            if ($profil !== null) {
                $this->demandProfiles[$product->id] = $profil;
            }

            $produk->push($product);
        }

        // Satu produk diarsipkan (soft delete) supaya filter "Arsip" punya isi.
        // Stoknya dibuat lewat Inbound sungguhan, bukan diisi langsung, supaya
        // invarian SUM(quantity) == current_stock tetap berlaku untuk produk arsip.
        $arsip = Product::factory()->create([
            'sku' => 'ELK-099',
            'name' => 'Kabel Listrik 5 Meter (henti produksi)',
            'category_id' => $this->categories['Elektronik']->id,
            'current_stock' => 0,
            'reorder_point' => 20,
        ]);

        $mapping->attach(
            product: $arsip,
            supplier: $this->suppliers['nusantara'],
            lastPurchasePrice: 32500,
            leadTimeDays: 7,
            user: $this->users['admin'],
        );

        // `movementDate` digeser 8 bulan ke belakang supaya produk ini terlihat
        // "dihentikan produksi" di tengah periode. Tapi `setTestNow()` juga harus
        // digeser: tanpa itu `created_at` jatuh ke waktu SEKARANG, dan Jejak
        // Audit akan menampilkan entri yang dicatat 242 hari setelah barangnya
        // masuk — berbeda 8 bulan dari Buku Besar.
        $sembilanBulanLalu = CarbonImmutable::now()->subMonths(8)->setTime(9, 20);

        try {
            CarbonImmutable::setTestNow($sembilanBulanLalu);

            app(InventoryMovementService::class)->recordInbound(
                product: $arsip,
                quantity: 40,
                user: $this->users['admin'],
                supplierId: $this->suppliers['nusantara']->id,
                referenceCode: 'PO-ARSIP-001',
                movementDate: $sembilanBulanLalu,
                unitPrice: 32500,
            );
        } finally {
            CarbonImmutable::setTestNow();
        }

        $arsip->delete();

        $this->products = $produk;
    }
}
