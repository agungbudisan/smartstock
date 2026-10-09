<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Auto-isi SKU di form produk.
 *
 * Aturan yang dijaga di sini semuanya soal hal yang tidak terlihat di layar
 * kalau rusak:
 *
 *  1. Saran hanya boleh mengisi field yang masih KOSONG atau yang isinya masih
 *     milik form. Menimpa SKU yang sudah diketik admin berarti memindahkan
 *     ketikan orang tanpa diminta.
 *  2. Auto-isi MATI di mode edit. SKU yang sudah pernah terbit bisa tercetak di
 *     label fisik dan sudah tercantum di PO yang keluar; berubah sendiri karena
 *     kategori diganti akan memutus keduanya.
 *  3. Saran dihitung server SEKALI saat halaman dirender, bukan lewat endpoint
 *     yang dipanggil tiap kali kategori dipilih.
 *
 * Perilaku ini murni sisi Vue dan tidak punya endpoint untuk diuji, jadi test
 * ini membaca sumbernya -- pola yang sama seperti `ProductFormPayloadTest`.
 * Yang tidak bisa diuji dengan membaca teks adalah "compile-nya benar"; itu
 * ditutup `VueTemplateBindingTest`.
 */
class SkuAutoFillTest extends TestCase
{
    private function sumber(string $berkas = 'Pages/Products/Form.vue'): string
    {
        return (string) file_get_contents(resource_path('js/'.$berkas));
    }

    /**
     * Auto-isi harus bergantung pada kategori yang dipilih.
     */
    public function test_auto_isi_memperhatikan_kategori_yang_dipilih(): void
    {
        $sumber = $this->sumber();

        $this->assertStringContainsString(
            'watch(',
            $sumber,
            'Auto-isi SKU harus dipicu oleh perubahan kategori',
        );

        $this->assertMatchesRegularExpression(
            '/watch\(\s*\(\) => form\.category_id/',
            $sumber,
            'watch harus mengamati kategori form, bukan isi form',
        );

        $this->assertStringContainsString(
            'props.saranSku[String(kategoriBaru)]',
            $sumber,
            'Saran diambil dari peta yang dikirim server',
        );
    }

    /**
     * Yang dijaga: SKU yang SUDAH DIKETIK admin tidak boleh ditimpa.
     *
     * Ini inti dari fitur ini. Tanpa penjaga `saranTerakhir`, setiap kali
     * kategori diubah, SKU yang diketik manual akan diganti diam-diam oleh
     * saran -- dan operator baru sadar setelah menekan Simpan.
     */
    public function test_sku_yang_sudah_diketik_tidak_ditimpa(): void
    {
        $sumber = $this->sumber();

        $this->assertStringContainsString(
            'let saranTerakhir',
            $sumber,
            'Harus ada catatan saran terakhir, untuk membedakan isian form dari ketikan admin',
        );

        $this->assertMatchesRegularExpression(
            '/if \(form\.sku && form\.sku !== saranTerakhir\) return;/',
            $sumber,
            'SKU yang berbeda dari saran sebelumnya berarti admin sudah mengetik -- jangan ditimpa',
        );
    }

    /**
     * Auto-isi harus mati di mode edit.
     */
    public function test_auto_isi_mati_di_mode_edit(): void
    {
        $sumber = $this->sumber();

        $this->assertMatchesRegularExpression(
            '/if \(props\.product\) return;/',
            $sumber,
            'Mode edit tidak boleh mengubah SKU yang sudah terbit',
        );
    }

    /**
     * Kategori tanpa prefix harus mengosongkan isian milik form, bukan
     * ketikan admin.
     */
    public function test_kategori_tanpa_prefix_mengosongkan_saran(): void
    {
        $sumber = $this->sumber();

        $this->assertStringContainsString(
            'if (!saran) {',
            $sumber,
            'Kategori tanpa prefix harus ditangani eksplisit',
        );

        $this->assertMatchesRegularExpression(
            '/if \(form\.sku === saranTerakhir\) form\.sku = \'\';/',
            $sumber,
            'Hanya isian milik form yang boleh dikosongkan',
        );
    }

    // -----------------------------------------------------------------
    // Prop harus diteruskan
    // -----------------------------------------------------------------

    public function test_create_meneruskan_saran_sku(): void
    {
        $sumber = $this->sumber('Pages/Products/Create.vue');

        $this->assertStringContainsString('saranSku', $sumber);
        $this->assertStringContainsString(':saran-sku="saranSku"', $sumber);
    }

    public function test_edit_meneruskan_saran_sku(): void
    {
        $sumber = $this->sumber('Pages/Products/Edit.vue');

        $this->assertStringContainsString(':saran-sku="saranSku"', $sumber);
    }

    /**
     * Petunjuk di bawah field SKU harus jujur soal kategori tanpa prefix.
     */
    public function test_petunjuk_membedakan_kategori_dengan_dan_tanpa_prefix(): void
    {
        $sumber = $this->sumber();

        $this->assertStringContainsString('v-if="saranSekarang"', $sumber);

        // Kategori tanpa prefix harus dijelaskan, bukan diam saja.
        $this->assertStringContainsString(
            'kategoriTerpilih && !kategoriTerpilih.sku_prefix',
            $sumber,
            'Harus ada keterangan eksplisit saat kategori tidak punya prefix',
        );
    }
}
