<?php

namespace Database\Seeders;

use App\Enums\MovementType;
use App\Models\Category;
use App\Models\DailyOutboundAggregation;
use App\Models\InventoryMovement;
use App\Models\InventoryTransaction;
use App\Models\MonthlyDemandPrediction;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeder demo untuk SmartStock.
 *
 * Jalankan:
 *   php artisan migrate:fresh --seed     # dari nol, paling disarankan
 *   php artisan db:seed                  # hanya isi ulang data
 *
 * AKUN DEMO (semua password: "password")
 *   admin@smartstock.test     Budi Santoso     Admin Gudang
 *   operator@smartstock.test  Siti Rahayu      Admin Gudang
 *   manager@smartstock.test   Dewi Lestari     Manager Bisnis
 *   pending@smartstock.test   (belum verifikasi) — untuk mencoba alur verifikasi
 *
 * Yang dihasilkan, dan kenapa penting untuk menguji aplikasi:
 *
 *  - Master data lengkap 5 kategori, 6 pemasok, 14 produk (1 diarsipkan),
 *    dengan pemetaan harga beli + lead time per pemasok.
 *  - 120 hari riwayat Inbound / Outbound / Stock Opname yang dibuat LEWAT
 *    InventoryMovementService, jadi `inventory_movements`, `audit_logs`,
 *    pivot harga, dan notifikasi stok menipis semuanya konsisten dengan
 *    `products.current_stock`.
 *  - Stok akhir sengaja ditata agar ada produk sehat, tepat di batas aman,
 *    di bawah batas aman, dan habis — supaya semua kartu UI Reorder punya
 *    contoh nyata.
 *  - Agregasi harian & prediksi bulanan dihitung sungguhan oleh job/service,
 *    termasuk backtest 5 bulan lampau untuk halaman Evaluasi Akurasi.
 *  - Dua produk sengaja tanpa riwayat, sebagai kasus "belum ada prediksi".
 *
 * CATATAN: seeder mengosongkan tabel domain lebih dulu, jadi `db:seed` bisa
 * dijalankan berulang tanpa menumpuk data.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $mulai = microtime(true);

        $this->command?->newLine();
        $this->command?->info('Seeding data demo SmartStock...');

        $this->kosongkan();

        $master = (new MasterDataSeeder)->setCommand($this->command);
        $master->run();

        $riwayat = (new MovementHistorySeeder($master))->setCommand($this->command);
        $riwayat->run();

        // TAHAP 4: panggilan backfill yang pernah ada di sini
        // dihapus. Every baris yang ditulis `MovementHistorySeeder` sudah
        // punya dokumen, karena `recordDocument()` membuatnya di dalam transaksi
        // yang sama — dan kolom yang dipakai backfill untuk menebak header
        // (`movement_type`, `supplier_id`, `user_id`) sudah dibuang dari tabel
        // baris detail. Jalur "baris tanpa dokumen" tidak bisa direpresentasikan
        // lagi sama sekali.

        $etl = (new AnalyticsSeeder)->setCommand($this->command);
        $etl->run();

        $this->command?->newLine();
        $this->command?->info(sprintf('Selesai dalam %.1f detik.', microtime(true) - $mulai));
        $this->tampilkanRingkasan();
    }

    /**
     * Kosongkan tabel domain. Urutan mengikuti ketergantungan foreign key.
     *
     * Tabel `users` ikut dikosongkan karena notifikasi DAN pergerakan merujuk
     * ke sana; akun demo dibuat ulang oleh MasterDataSeeder.
     */
    private function kosongkan(): void
    {
        DB::table('notifications')->delete();
        InventoryMovement::query()->delete();
        InventoryTransaction::query()->delete();
        DailyOutboundAggregation::query()->delete();
        MonthlyDemandPrediction::query()->delete();
        DB::table('audit_logs')->delete();
        DB::table('product_supplier')->delete();

        // Product memakai SoftDeletes, jadi delete() hanya mengarsipkan —
        // harus forceDelete() supaya SKU lama tidak bentrok saat insert lagi.
        Product::withTrashed()->forceDelete();
        Supplier::query()->forceDelete();
        Category::query()->forceDelete();
        User::query()->forceDelete();
    }

    private function tampilkanRingkasan(): void
    {
        $baris = [
            'Produk aktif' => Product::count(),
            'Produk diarsipkan' => Product::onlyTrashed()->count(),
            'Pemasok' => Supplier::count(),
            'Kategori' => Category::count(),
            'Pengguna' => User::count(),
            // Jenis hidup di dokumen sejak tahap 4, jadi dihitung lewat scope
            // yang membaca header. where('movement_type', ...) di baris detail
            // tidak error di SQLite - ia mengembalikan 0 baris diam-diam,
            // karena SQLite menganggap identifier bertanda kutip itu string.
            'Inbound' => InventoryMovement::ofMovementType(MovementType::Inbound)->count(),
            'Outbound' => InventoryMovement::ofMovementType(MovementType::Outbound)->count(),
            'Stock opname' => InventoryMovement::ofMovementType(MovementType::Adjustment)->count(),
            'Dokumen' => InventoryTransaction::count(),
            'Notifikasi' => DB::table('notifications')->count(),
            'Agregasi harian' => DailyOutboundAggregation::count(),
            'Prediksi bulanan' => MonthlyDemandPrediction::count(),
            'Perlu reorder' => Product::needingReorder()->count(),
            'Stok habis' => Product::where('current_stock', 0)->count(),
        ];

        $this->command?->table(['Tabel', 'Jumlah'], array_map(
            fn (string $label, int $jumlah): array => [$label, number_format($jumlah, 0, ',', '.')],
            array_keys($baris),
            array_values($baris),
        ));

        $this->command?->info('  Masuk sebagai:');
        $this->command?->line('    admin@smartstock.test    (Admin Gudang)');
        $this->command?->line('    operator@smartstock.test (Admin Gudang)');
        $this->command?->line('    manager@smartstock.test  (Manager Bisnis)');
        $this->command?->line('    password untuk ketiganya');
        $this->command?->newLine();
    }
}
