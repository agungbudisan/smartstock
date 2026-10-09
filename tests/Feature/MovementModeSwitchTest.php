<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Isian form harus dibersihkan saat mode pergerakan diganti.
 *
 * Ditemukan user: nomor referensi bertahan dari Inbound ke Outbound, jadi admin
 * harus menghapusnya manual. `reference_code` ke `inventory_movements`
 * dan ke description audit apa adanya, jadi nomor yang nyasar tidak bisa
 * diperbaiki belakangan — mengetik ulang lebih murah daripada dokumen palsu.
 *
 * Perilaku ini murni sisi Vue dan tidak punya endpoint untuk diuji, jadi test
 * ini membaca sumber `Movements.vue` — pola yang sama seperti
 * `UseFormApiContractTest`. Yang diperiksa: `setMode()` membersihkan
 * `reference_code`, dan TIDAK membersihkan field yang memang lintas mode
 * (produk, tanggal, jam).
 */
class MovementModeSwitchTest extends TestCase
{
    private function sumber(): string
    {
        return (string) file_get_contents(resource_path('js/Pages/Inventory/Movements.vue'));
    }

    /**
     * Ambil isi blok fungsi `setMode` — dari `function setMode(` sampai
     * kurung Kurawal penutup di indentasi yang sama.
     */
    private function blokSetMode(string $sumber): string
    {
        $mulai = strpos($sumber, 'function setMode(');

        $this->assertNotFalse($mulai, 'setMode() tidak ditemukan di Movements.vue');

        $akhir = strpos($sumber, "\n}", $mulai);

        $this->assertNotFalse($akhir, 'Blok setMode() tidak tertutup');

        return substr($sumber, $mulai, $akhir - $mulai);
    }

    // -----------------------------------------------------------------
    // Yang harus dibersihkan
    // -----------------------------------------------------------------

    public function test_nomor_referensi_dibersihkan_saat_mode_berganti(): void
    {
        $blok = $this->blokSetMode($this->sumber());

        $this->assertMatchesRegularExpression(
            '/form\.reference_code\s*=\s*\'\'/',
            $blok,
            'reference_code harus dikosongkan saat mode diganti',
        );
    }

    public function test_pemasok_dan_harga_dibersihkan_saat_mode_berganti(): void
    {
        $blok = $this->blokSetMode($this->sumber());

        $this->assertStringContainsString("form.supplier_id = ''", $blok);

        // Sejak tahap 3 harga ada di setiap baris, bukan satu kolom global,
        // jadi yang dibersihkan adalah `unit_price` tiap baris.
        $this->assertStringContainsString('unit_price = null', $blok);
    }

    public function test_kolom_yang_eksklusif_salah_mode_tetap_dibersihkan(): void
    {
        $blok = $this->blokSetMode($this->sumber());

        // physical_count milik opname, quantity milik inbound/outbound. Keduanya
        // kini per baris, bukan field datar.
        $this->assertStringContainsString('quantity', $blok);
        $this->assertStringContainsString('physical_count', $blok);
    }

    // -----------------------------------------------------------------
    // Yang harus TIDAK dibersihkan
    // -----------------------------------------------------------------

    public function test_baris_dan_waktu_tetap_bertahan_saat_mode_berganti(): void
    {
        $blok = $this->blokSetMode($this->sumber());

        // Baris item tidak boleh dihapus: berpindah dari Inbound 3 baris ke
        // Outbound tidak boleh menghapus kerja yang sudah diketik.
        $this->assertStringNotContainsString(
            'form.items.length = 0',
            $blok,
            'Daftar baris tidak boleh dikosongkan saat mode diganti',
        );

        $this->assertStringNotContainsString(
            'form.items = []',
            $blok,
            'Daftar baris tidak boleh diganti dengan array kosong saat mode diganti',
        );

        // Waktu transaksi juga lintas mode.
        $this->assertStringNotContainsString('form.tanggal =', $blok);
        $this->assertStringNotContainsString('form.waktu =', $blok);
    }

    // -----------------------------------------------------------------
    // Label mode-aware
    // -----------------------------------------------------------------

    public function test_label_referensi_berubah_ikut_mode(): void
    {
        $sumber = $this->sumber();

        $this->assertStringContainsString('referensiPerMode', $sumber);
        $this->assertStringContainsString('Nomor PO / faktur pembelian', $sumber);
        $this->assertStringContainsString('Nomor SO / nota pengiriman', $sumber);
        $this->assertStringContainsString('Nomor berita acara opname', $sumber);

        // Label tidak boleh lagi generik -- generic label menutupi fakta bahwa
        // nomor ini berbeda jenis tiap mode.
        $this->assertStringNotContainsString(
            '>Nomor referensi / faktur<',
            $sumber,
            'Label generik menyembunyikan bahwa nomor referensi spesifik per mode',
        );
    }

    public function test_kolom_referensi_menampilkan_pesan_kesalahan(): void
    {
        $sumber = $this->sumber();

        $this->assertStringContainsString(
            'form.errors.reference_code',
            $sumber,
            'Kolom referensi harus punya tempat untuk pesan error-nya sendiri',
        );
    }

    // -----------------------------------------------------------------
    // Kolom mana yang boleh muncul per mode
    // -----------------------------------------------------------------

    /**
     * Kolom yang tidak berlaku untuk mode aktif HARUS disembunyikan, bukan
     * sekadar diberi label lain.
     *
     * Ditemukan user: di mode Stock Opname kolom "Jumlah Keluar" ikut tampil.
     * Kerusakannya bukan cuma tampilan. Kolom itu `required`, jadi kalau
     * dikosongkan peramban memblokir simpan dengan pesan pada field yang tak
     * berarti; kalau diisi, nilainya DIAM-DIAM diabaikan server karena untuk
     * adjustment service memakai `physical_count`. Angka yang diketik hilang
     * tanpa penjelasan lebih buruk daripada error.
     *
     * Tiga kolom, tiga aturan berbeda:
     *   - `quantity`       -> bukan adjustment (label mode-aware)
     *   - `physical_count` -> adjustment saja
     *   - `unit_price`     -> inbound saja
     */
    public function test_kolom_yang_tidak_berlaku_disembunyikan_per_mode(): void
    {
        $sumber = $this->sumber();

        $this->assertMatchesRegularExpression(
            '/v-if="mode !== \'adjustment\'"[^>]*>\s*<label[^>]*>\s*\{\{ mode === \'inbound\' \? /',
            $sumber,
            'Kolom jumlah harus disembunyikan untuk adjustment, bukan hanya diganti labelnya',
        );

        $this->assertMatchesRegularExpression(
            '/v-else[^>]*>\s*<label[^>]*>Jumlah fisik hasil hitung/',
            $sumber,
            'Angka fisik harus mengisi kolom utama baris saat mode adjustment',
        );

        $this->assertMatchesRegularExpression(
            '/v-if="mode === \'inbound\'"[^>]*>\s*<label[^>]*>\s*Harga satuan/',
            $sumber,
            'Harga satuan hanya berlaku untuk barang masuk',
        );
    }

    /**
     * Label "Jumlah keluar" hanya boleh muncul sebagai cabang yang sudah
     * dijaga `v-if` di atas -- tidak boleh jadi nilai bawaan dari sebuah
     * ternary dua arah, karena itulah bentuk yang membuat adjustment ikut
     * memakainya.
     */
    public function test_label_jumlah_keluar_bukan_nilai_bawaan_ternary(): void
    {
        $sumber = $this->sumber();

        $this->assertStringNotContainsString(
            "mode === 'inbound' ? 'Jumlah masuk' : 'Jumlah keluar'",
            $sumber,
            'Ternary dua arah membuat Stock Opname ikut menampilkan "Jumlah Keluar"',
        );
    }

    /**
     * Jumlah kolom per baris harus pas di grid 12 kolom supaya tidak ada
     * celah menyisakan ruang kosong yang terlihat seperti ada isi yang
     * terpotong.
     */
    public function test_baris_mengisi_grid_penuh_di_semua_mode(): void
    {
        $sumber = $this->sumber();

        // adjustment: produk 5 + fisik 5 + tombol 2 = 12
        $this->assertStringContainsString('v-else class="sm:col-span-5"', $sumber);

        // non-inbound tanpa kolom harga: produk 5 + jumlah 5 + tombol 2 = 12
        $this->assertStringContainsString(
            "'sm:col-span-3' : 'sm:col-span-5'",
            $sumber,
            'Kolom jumlah harus melebar saat kolom harga disembunyikan',
        );

        // inbound: produk 5 + jumlah 3 + harga 2 + tombol 2 = 12
        $this->assertStringContainsString('v-if="mode === \'inbound\'" class="sm:col-span-2"', $sumber);
    }

    /**
     * Harga belong di baris, bukan di dokumen.
     *
     * Ditemukan saat memeriksa kolom per mode: kolom "Harga beli satuan
     * (opsional)" masih ada di level dokumen walaupun `useForm` sudah tidak
     * punya key itu. `v-model` membuatnya kembali begitu operator mengetik,
     * dan `useForm().data()` mengirim SELURUH key objek form — menyembunyikan
     * kolom dengan `v-if` tidak membuatnya berhenti terkirim.
     *
     * Akibatnya ada dua sumber harga dalam satu request, dan harga per baris
     * menang tanpa pemberitahuan. Form harus hanya punya satu tempat bertanya
     * harga, yaitu tiap baris.
     */
    public function test_hanya_ada_satu_tempat_untuk_harga(): void
    {
        $sumber = $this->sumber();

        $this->assertStringNotContainsString(
            'form.unit_price',
            $sumber,
            'unit_price tidak boleh ada di level dokumen -- harga milik tiap baris',
        );

        $this->assertStringNotContainsString(
            'for="price"',
            $sumber,
            'Input harga tingkat dokumen sudah dihapus; jangan dikembalikan',
        );

        // Harga per baris harus tetap ada.
        $this->assertStringContainsString('baris.unit_price', $sumber);
    }

    /**
     * Label optgroup sudah harus menyebut "produk di faktur ini".
     *
     * Kamus dropdown berasal dari GABUNGAN seluruh produk di baris, bukan satu
     * produk. Label "produk ini" menyesatkan begitu baris lebih dari satu:
     * operator akan mencari pemasok untuk produk yang tidak ada di faktur ini.
     */
    public function test_label_pemasok_menyebut_faktur_bukan_produk_tunggal(): void
    {
        $sumber = $this->sumber();

        $this->assertStringNotContainsString(
            'produk ini (${supplierTerpilih.length})',
            $sumber,
            'Label optgroup harus menyebut produk di faktur ini, bukan "produk ini"',
        );

        $this->assertStringNotContainsString(
            'produk ini (${supplierLain.length})',
            $sumber,
            'Label optgroup harus menyebut produk di faktur ini, bukan "produk ini"',
        );

        $this->assertStringContainsString('produk di faktur ini', $sumber);
    }

    // -----------------------------------------------------------------
    // Konsistensi dengan pemulihan state
    // -----------------------------------------------------------------

    /**
     * Membersihkan `reference_code` saat mode diganti TIDAK boleh merusak
     * pemulihan isian setelah validasi gagal: kalau outbound ditolak karena
     * stok kurang, nomor SO-nya harus kembali, bukan hilang.
     */
    public function test_referensi_masih_pulih_setelah_validasi_gagal(): void
    {
        $controller = (string) file_get_contents(
            app_path('Http/Controllers/Inventory/MovementController.php'),
        );

        $this->assertStringContainsString(
            "'reference_code'",
            $controller,
            'entriLama() harus mengirim reference_code supaya isiannya pulih',
        );
    }
}
