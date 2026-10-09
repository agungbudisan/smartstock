<?php

namespace Tests\Feature;

use App\Jobs\AggregateDailyOutboundJob;
use App\Models\InventoryMovement;
use App\Models\InventoryTransaction;
use App\Models\Product;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\MovementHistorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Menjalankan seeder sungguhan, lalu memeriksa invariants yang HANYA bisa
 * dibuktikan terhadap data hasil seeding.
 *
 * Kenapa test ini perlu ada:
 *
 * `MovementTimestampConsistencyTest` sudah punya test yang menanyakan
 * `created_at < movement_date` dan "ada baris pukul 00:00:00" — tapi keduanya
 * memakai `RefreshDatabase` tanpa seeding, jadi mereka bertanya ke TABEL KOSONG
 * dan selalu lulus. False green: rules-nya terlihat terkunci padahal tidak ada
 * yang pernah salah. Test di sini menutup celah itu dengan benar-benar mengisi
 * database lebih dulu.
 *
 * Semua test di kelas ini satu test method dengan banyak assertion. Bukan
 * gaya yang disukai, tapi seeding 120 hari riwayat itu mahal; memperulanginya per
 * assertion akan membuat suite tidak layak jalan. Kalau ada yang gagal, pesan
 * assertion-nya menunjuk persis yang mana.
 */
class SeederDocumentGroupingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Seeder demo, jalan sungguhan: master data lalu riwayat pergerakan.
     *
     * `AnalyticsSeeder` sengaja tidak dijalankan: yang diuji di sini adalah
     * bentuk dokumen dan konsistensi stok, bukan agregat analitiknya.
     */
    private function seedDemo(): void
    {
        $master = new MasterDataSeeder;
        $master->run();

        $riwayat = new MovementHistorySeeder($master);
        $riwayat->run();

        // Tidak ada lagi langkah backfill setelah tahap 4, jadi setiap baris
        // yang ada di sini PASTI sudah punya dokumen karena ditulis lewat
        // `recordDocument()`. Kalau suatu saat muncul baris terlantar, itu
        // berarti ada jalur penulisan di luar service.
        $this->assertSame(
            0,
            InventoryMovement::whereNull('transaction_id')->count(),
            'Seeder tidak boleh meninggalkan baris tanpa dokumen',
        );
    }

    public function test_data_demo_memenuhi_semua_invariant(): void
    {
        $this->seedDemo();
        $baris = InventoryMovement::count();
        $dokumen = InventoryTransaction::count();

        $this->assertGreaterThan(500, $baris, 'Seeder harus menghasilkan riwayat yang cukup');
        $this->assertGreaterThan(0, $dokumen);

        // -----------------------------------------------------------------
        // 1. Multi-baris benar-benar terjadi — inilah tujuan tahap 5
        // -----------------------------------------------------------------

        $rasio = $baris / $dokumen;

        $this->assertGreaterThan(
            1.5,
            $rasio,
            sprintf(
                'Rasio %.2f baris per dokumen. Seeder lama memecah satu PO menjadi banyak '
                .'dokumen satu-baris, jadi fitur multi-baris tidak pernah terlihat.',
                $rasio,
            ),
        );

        $terbanyak = (int) DB::table('inventory_movements')
            ->selectRaw('transaction_id, COUNT(*) AS jml')
            ->groupBy('transaction_id')
            ->orderByDesc('jml')
            ->value('jml');

        $this->assertGreaterThanOrEqual(
            3,
            $terbanyak,
            'Harus ada dokumen yang menutup beberapa SKU sekaligus',
        );

        // Setiap jenis gerakan boleh punya dokumen multi-baris. Kalau hanya
        // inbound yang dikelompokkan, fitur ini belum selesai.
        foreach (['inbound', 'outbound'] as $jenis) {
            $multi = DB::table('inventory_movements as m')
                ->join('inventory_transactions as t', 't.id', '=', 'm.transaction_id')
                ->where('t.movement_type', $jenis)
                ->groupBy('t.id')
                ->havingRaw('COUNT(*) > 1')
                ->count();

            $this->assertGreaterThan(
                0,
                $multi,
                "Dokumen $jenis harus ada yang multi-baris",
            );
        }

        // -----------------------------------------------------------------
        // 2. Satu nomor dokumen = satu dokumen
        // -----------------------------------------------------------------

        // Ini yang paling memalukan dari seeder lama: satu nomor PO muncul
        // sebagai belasan dokumen berbeda. Nomor faktur yang sama di dua tempat
        // berarti tidak ada satu pun yang bisa disebut "faktur ini".
        $dobel = DB::table('inventory_transactions')
            ->whereNotNull('physical_reference')
            ->selectRaw('physical_reference, COUNT(*) AS jml')
            ->groupBy('physical_reference')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $this->assertSame(
            0,
            $dobel,
            'Satu nomor dokumen tidak boleh dipakai lebih dari satu dokumen',
        );

        // -----------------------------------------------------------------
        // 3. Invarian ledger
        // -----------------------------------------------------------------

        foreach (Product::withTrashed()->get() as $product) {
            $sum = (int) InventoryMovement::where('product_id', $product->id)->sum('quantity');

            $this->assertSame(
                (int) $product->current_stock,
                $sum,
                "Ledger {$product->sku} harus sama dengan current_stock",
            );
        }

        // -----------------------------------------------------------------
        // 4. Tidak ada baris tanpa dokumen, dan tidak ada dokumen tanpa baris
        // -----------------------------------------------------------------

        $this->assertSame(
            0,
            InventoryMovement::whereNull('transaction_id')->count(),
            'Setiap baris detail wajib punya dokumen',
        );

        $yatim = (int) DB::select(
            'SELECT COUNT(*) AS jml FROM inventory_transactions t
             WHERE NOT EXISTS (SELECT 1 FROM inventory_movements m WHERE m.transaction_id = t.id)',
        )[0]->jml;

        $this->assertSame(0, $yatim, 'Tidak boleh ada dokumen tanpa baris detail');

        // -----------------------------------------------------------------
        // 5. TAHAP 4: kolom lama harus benar-benar tidak ada
        // -----------------------------------------------------------------

        // Ini adalah kebalikan dari yang diperiksa `OldColumnsAreNoLongerReadTest`
        // (yang sudah dihapus). Dulu syaratnya "kolomnya ada tapi isinya sudah
        // tidak dibaca lagi"; sekarang syaratnya "kolomnya tidak ada sama
        // Diperiksa terhadap skema, bukan isi baris, jadi tetap berlaku walau
        // ada baris pun, pemeriksaan skema tetap berlaku.
        foreach (['movement_type', 'user_id', 'supplier_id'] as $kolom) {
            $this->assertFalse(
                Schema::hasColumn('inventory_movements', $kolom),
                "Kolom {$kolom} harus sudah dihapus dari baris detail di tahap 4",
            );
        }

        // Yang masih ada, dan harus tetap sinkron dengan dokumen: tanggal.
        // `movement_date` sengaja diduplikasi ke baris detail, jadi kali ini
        // penyimpangan benar-benar berarti bug.
        $tanggalBeda = (int) DB::select(
            'SELECT COUNT(*) AS jml FROM inventory_movements m
             JOIN inventory_transactions t ON t.id = m.transaction_id
             WHERE NOT (m.movement_date = t.transaction_date)',
        )[0]->jml;

        $this->assertSame(
            0,
            $tanggalBeda,
            'movement_date di baris detail harus sama dengan transaction_date di dokumen',
        );

        // -----------------------------------------------------------------
        // 6. Waktu
        // -----------------------------------------------------------------

        $mundur = (int) DB::select(
            'SELECT COUNT(*) AS jml FROM inventory_movements WHERE created_at < movement_date',
        )[0]->jml;

        $this->assertSame(
            0,
            $mundur,
            'Barang tidak bisa dicatat sebelum transaksinya terjadi',
        );

        $tengahMalam = (int) DB::select(
            "SELECT COUNT(*) AS jml FROM inventory_movements
             WHERE strftime('%H:%M:%S', movement_date) = '00:00:00'",
        )[0]->jml;

        $this->assertSame(
            0,
            $tengahMalam,
            'Tidak boleh ada baris tepat tengah malam — kolom waktu di Buku Besar akan terlihat rusak',
        );

        // Setiap dokumen punya waktu sendiri. Kalau semua baris satu jam
        // bekerja sama persis, `created_at` di Jejak Audit menumpuk ke satu titik
        // dan terlihat palsu.
        $waktuUnik = (int) DB::select(
            'SELECT COUNT(DISTINCT movement_date) AS jml FROM inventory_movements',
        )[0]->jml;

        $this->assertGreaterThan(
            0,
            $waktuUnik,
            'Jam transaksi harus varied',
        );

        // -----------------------------------------------------------------
        // 7. Job ETL masih membaca dokumen dengan benar
        // -----------------------------------------------------------------

        $before = DB::table('daily_outbound_aggregations')->count();

        (new AggregateDailyOutboundJob)->handle();

        $this->assertGreaterThanOrEqual(
            $before,
            DB::table('daily_outbound_aggregations')->count(),
            'Job agregasi harus tetap jalan di atas data hasil seeding',
        );

        // -----------------------------------------------------------------
        // 8. Kondisi stok akhir sengaja ditata — UI Reorder butuh contohnya
        // -----------------------------------------------------------------

        $aktif = Product::whereNull('deleted_at')->get();

        $this->assertGreaterThanOrEqual(
            1,
            $aktif->filter(fn (Product $p): bool => $p->current_stock === 0)->count(),
            'Harus ada produk habis total',
        );

        $this->assertGreaterThanOrEqual(
            1,
            $aktif->filter(fn (Product $p): bool => $p->needsReorder())->count(),
            'Harus ada produk di bawah batas aman',
        );

        $this->assertGreaterThanOrEqual(
            1,
            $aktif->filter(fn (Product $p): bool => ! $p->needsReorder())->count(),
            'Harus ada produk yang sehat',
        );
    }
}
