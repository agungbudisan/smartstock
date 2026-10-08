<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryMovementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `movement_date` (waktu transaksi) dan `created_at` (waktu pencatatan) itu
 * dua hal berbeda, dan harus tetap begitu.
 *
 * Buku Besar menampilkan `movement_date`; Jejak Audit menampilkan
 * `created_at`. Keduanya memang BOLEH berbeda — barang bisa datang 14:00 lalu
 * dicatat 16:00 — jadi perbedaan itu normal, bukan bug.
 *
 * Yang tidak normal adalah data demo yang membuatnya terlihat seperti bug:
 *
 *  1. Seeder dulu mengunci `created_at` per hari (`setTestNow(...17:00)`),
 *     sehingga 911 dari 915 baris mendapat cap yang IDENTIK. Buku Besar
 *     menampilkan 08:21, 10:37, 15:20 sementara Jejak Audit menampilkan
 *     17:00 untuk ketiganya.
 *  2. `MasterDataSeeder` mengarang `movementDate` 8 bulan ke belakang tanpa
 *     `setTestNow()`, jadi `created_at`-nya jatuh ke waktu nyata — selisih
 *     242 hari antara dua kolom itu.
 *  3. `created_at` yang mendahului `movement_date` berarti barang tercatat
 *     dicatat SEBELUM terjadi. Itu tidak mungkin.
 *
 * Test di sini mengunci ketiga aturan, plus-sponsored bahwa jarak antara dua
 * kolom tetap masuk akal (meniru jeda mengetik manusia).
 */
class MovementTimestampConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private InventoryMovementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->adminGudang()->create();
        $this->service = new InventoryMovementService;
    }

    // -----------------------------------------------------------------
    // Aturan yang berlaku untuk semua data
    // -----------------------------------------------------------------

    /*
     * DUA TEST YANG PERNAH DI SINI SUDAH DIHAPUS, dan alasannya penting.
     *
     * `test_created_at_tidak_boleh_mendahului_movement_date` dan
     * `test_tidak_ada_movement_yang_tepat_tengah_malam` menanyakan ke
     * `inventory_movements` — TAPI kelas ini pakai `RefreshDatabase` tanpa
     * seeding, jadi keduanya bertanya ke TABEL KOSONG dan selalu lulus.
     * False green: aturannya terlihat terkunci padahal tidak ada seeder pun
     * yang pernah diuji. Dua aturan itu persis berasal dari bug sungguhan di
     * seeder, jadi "lulus" mereka selama ini tidak berarti apa-apa.
     *
     * Sekarang keduanya diuji dengan benar di
     * `SeederDocumentGroupingTest`, yang menjalankan seeder sungguhan lebih
     * dulu. Jangan menambah balik query ke tabel data kosong di sini: test
     * yang tidak bisa gagal adalah kebohongan yang menambah rasa aman.
     */

    /**
     * `created_at` diambil dari `now()`, `movement_date` dari argumen eksplisit.
     *
     * Kalau `now()` dikunci ke satu titik (persis seperti yang terjadi kalau seeder
     * mempatok `setTestNow()` per hari), semua entri mendapat cap yang sama —
     * itulah akar masalah yang diperbaiki `catatPada()`. Test ini mengunci mekanismenya:
     * menggeser `now()` menggeser `created_at`, sementara `movement_date` tetap
     * mengikuti argumennya.
     */
    public function test_created_at_mengikuti_sekarang_sedangkan_movement_date_ikut_argumen(): void
    {
        $product = Product::factory()->create(['current_stock' => 0]);

        $tanggalSama = CarbonImmutable::parse('2026-06-10 08:00:00');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 17:00:00'));
        $this->service->recordInbound($product, 5, $this->admin, referenceCode: 'STACK-A', movementDate: $tanggalSama);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 09:05:00'));
        $this->service->recordInbound($product, 5, $this->admin, referenceCode: 'STACK-B', movementDate: $tanggalSama);

        CarbonImmutable::setTestNow();

        $a = InventoryMovement::where('reference_code', 'STACK-A')->firstOrFail();
        $b = InventoryMovement::where('reference_code', 'STACK-B')->firstOrFail();

        // created_at mengikuti now() yang bergeser.
        $this->assertSame('2026-06-10 17:00:00', $a->created_at->toDateTimeString());
        $this->assertSame('2026-06-10 09:05:00', $b->created_at->toDateTimeString());
        $this->assertNotSame($a->created_at->toDateTimeString(), $b->created_at->toDateTimeString());

        // movement_date tetap sama karena argumennya sama.
        $this->assertSame(
            $a->movement_date->toDateTimeString(),
            $b->movement_date->toDateTimeString(),
        );
    }

    public function test_selisih_antar_kedua_waktu_masuk_akal(): void
    {
        $product = Product::factory()->create(['current_stock' => 0]);

        // Barang terjadi 10:00, dicatat 10:25 (jeda mengetik).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 10:25:00'));

        $this->service->recordInbound(
            $product, 10, $this->admin,
            referenceCode: 'JEDA-1',
            movementDate: CarbonImmutable::parse('2026-06-10 10:00:00'),
        );

        // Barang terjadi 09:00, dicatat 09:01.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 09:01:00'));

        $this->service->recordInbound(
            $product, 10, $this->admin,
            referenceCode: 'JEDA-2',
            movementDate: CarbonImmutable::parse('2026-06-10 09:00:00'),
        );

        CarbonImmutable::setTestNow();

        $satu = InventoryMovement::where('reference_code', 'JEDA-1')->firstOrFail();
        $dua = InventoryMovement::where('reference_code', 'JEDA-2')->firstOrFail();

        $this->assertSame(25, (int) $satu->movement_date->diffInMinutes($satu->created_at));
        $this->assertSame(1, (int) $dua->movement_date->diffInMinutes($dua->created_at));

        // Keduanya pada hari yang sama, jadi selisihnya tidak mungkin melompati satu hari.
        $this->assertTrue($satu->created_at->isSameDay($satu->movement_date));
        $this->assertTrue($dua->created_at->isSameDay($dua->movement_date));
    }

    // -----------------------------------------------------------------
    // Jejak audit mengikuti waktu pencatatan, bukan waktu transaksi
    // -----------------------------------------------------------------

    public function test_jejak_audit_mencatat_waktu_pencatatan(): void
    {
        $product = Product::factory()->create(['current_stock' => 0]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 15:30:00'));

        $this->service->recordInbound(
            $product, 10, $this->admin,
            referenceCode: 'AUDIT-1',
            movementDate: CarbonImmutable::parse('2026-06-09 08:00:00'),
        );

        CarbonImmutable::setTestNow();

        $audit = AuditLog::where('description', 'like', '%ref AUDIT-1%')->firstOrFail();
        $movement = InventoryMovement::where('reference_code', 'AUDIT-1')->firstOrFail();

        // Jejak audit menunjuk ke saat entri dibuat, bukan saat barang bergerak.
        $this->assertSame(
            '2026-06-10 15:30:00',
            $audit->created_at->toDateTimeString(),
        );

        $this->assertSame(
            '2026-06-09 08:00:00',
            $movement->movement_date->toDateTimeString(),
        );

        // Dua kolom memang berbeda — itu definisinya, bukan kegagalan.
        $this->assertNotSame($audit->created_at, $movement->movement_date);
    }

    public function test_kedua_kolom_boleh_sama_jika_dicatat_langsung(): void
    {
        $product = Product::factory()->create(['current_stock' => 0]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 11:00:00'));

        $this->service->recordInbound($product, 10, $this->admin, referenceCode: 'SAMA-1');

        CarbonImmutable::setTestNow();

        $movement = InventoryMovement::where('reference_code', 'SAMA-1')->firstOrFail();

        // Ini yang terjadi saat admin tidak mengubah kolom tanggal: keduanya
        // identik. Bukan bug, cuma tidak ada informasi tambahan.
        $this->assertSame(
            $movement->movement_date->toDateTimeString(),
            $movement->created_at->toDateTimeString(),
        );
    }

    // -----------------------------------------------------------------
    // Aturan kode seeder
    // -----------------------------------------------------------------

    public function test_seeder_menocsongkan_waktu_transaksi_dan_pencatatan(): void
    {
        $seeder = (string) file_get_contents(base_path('database/seeders/MovementHistorySeeder.php'));

        // Dua nama parameter, bukan satu: sejak tahap 2 seeder memakai
        // `recordDocument(transactionDate:)` sementara versi lama memakai
        // `recordInbound(movementDate:)`. Keduanya harus lewat catatPada(),
        // yang selain mengacak jam kerja juga menggeser setTestNow ke waktu
        // pencatatan. Kalau ada yang menyamping, `created_at` menumpuk di satu
        // titik dan kolom waktu di Jejak Audit terlihat palsu.
        $calls = preg_match_all(
            '/(movementDate|transactionDate):\s*\$this->(waktuKerja|catatPada)\(/',
            $seeder,
            $m,
        );
        $lewatCatatPada = preg_match_all(
            '/(movementDate|transactionDate):\s*\$this->catatPada\(/',
            $seeder,
        );

        $this->assertGreaterThan(0, $calls, 'Seeder harus tetap mengoper waktu transaksi eksplisit');
        $this->assertSame(
            $calls,
            $lewatCatatPada,
            'Sebagian waktu transaksi yang lewat tanpa catatPada() sehingga created_at menumpuk di satu titik',
        );
    }

    /**
     * Seeder harus memakai `recordDocument()`, bukan perulangan per produk.
     *
     * Perulangan `recordInbound()` satu produk sekali menghasilkan satu dokumen
     * per panggilan, jadi satu purchase order yang menutup sepuluh SKU muncul
     * sebagai sepuluh dokumen dengan nomor yang sama — dan tidak ada satu pun
     * yang bisa dibaca sebagai satu faktur. Test integrasi
     * (`SeederDocumentGroupingTest`) membuktikan efeknya terhadap data; test ini
     * hanya menjaga agar jalan pintas itu tidak diam-diam kembali.
     */
    public function test_seeder_mencatat_dokumen_bukan_baris_per_produk(): void
    {
        // Komentar DIBUANG dulu sebelum dipindai. Docblock seeder sengaja
        // menjelaskan kenapa `recordInbound()` per produk ditinggalkan, jadi
        // pemindaian mentah akan mencocokkan kalimat itu dan menuduh seeder
        // memakai jalan yang justru sedang dilarang.
        $seeder = php_strip_whitespace(base_path('database/seeders/MovementHistorySeeder.php'));

        $this->assertStringContainsString(
            '$this->catatRencana(',
            $seeder,
            'Seeder harus mencatat lewat catatRencana() yang mengelompokkan baris per dokumen',
        );

        foreach (['recordInbound(', 'recordOutbound(', 'recordStockOpname('] as $tunnel) {
            $this->assertStringNotContainsString(
                $tunnel,
                $seeder,
                "Seeder tidak lagi memakai {$tunnel} per produk — itu memecah satu faktur jadi banyak dokumen",
            );
        }
    }

    public function test_master_seeder_menocsongkan_set_test_now_saat_mengarang_tanggal_lampau(): void
    {
        $seeder = (string) file_get_contents(base_path('database/seeders/MasterDataSeeder.php'));

        $this->assertStringContainsString(
            'CarbonImmutable::setTestNow',
            $seeder,
            'MasterDataSeeder mengarang movementDate 8 bulan ke belakang; tanpa setTestNow, '
            .'created_at jatuh ke waktu nyata dan selisihnya jadi ratusan hari',
        );

        $this->assertStringContainsString(
            'finally {',
            $seeder,
            'setTestNow() harus selalu dibongkar, kalau tidak sisa seeder berjalan di masa lampau',
        );
    }
}
