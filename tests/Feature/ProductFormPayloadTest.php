<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Payload form produk harus cocok dengan aturan validasi server.
 *
 * Regresi: `current_stock` tetap ada sebagai key di objek `useForm()` walau
 * kolomnya disembunyikan saat edit. Karena `useForm().data()` mengambil SELURUH
 * key dari objek form — bukan hanya yang dirender di template — field itu ikut
 * terkirim saat PATCH, lalu server menolaknya dengan aturan `prohibited`.
 * Akibatnya klik "Simpan" tidak menyimpan apa pun.
 *
 * Dan kegagalan itu SUNYI: pesan errornya tidak punya kolom di layar, tidak
 * muncul di console, jadi terlihat seperti tombolnya tidak berfungsi.
 *
 * Yang diperiksa di sini adalah sumber Vue-nya, karena payload form hanya
 * terjadi di browser. Test HTTP tidak akan pernah menangkapnya.
 */
class ProductFormPayloadTest extends TestCase
{
    private function sumber(): string
    {
        return (string) file_get_contents(resource_path('js/Pages/Products/Form.vue'));
    }

    /**
     * Objek literal yang dikirim ke `useForm(...)`, TANPA pembungkus
     * `useForm(` dan `)` — supaya propertinya berada di kedalaman 1.
     */
    private function blokUseForm(string $kode): string
    {
        $mulai = strpos($kode, 'useForm({');

        $this->assertNotFalse($mulai, 'Blok useForm tidak ditemukan di Form.vue');

        $awalObjek = $mulai + strlen('useForm(');
        $this->assertSame('{', $kode[$awalObjek], 'useForm harus dipanggil dengan objek literal');

        $kedalaman = 0;
        $kutip = null;
        $panjang = strlen($kode);

        for ($i = $awalObjek; $i < $panjang; $i++) {
            $karakter = $kode[$i];

            if ($kutip !== null) {
                if ($karakter === '\\') {
                    $i++;

                    continue;
                }

                if ($karakter === $kutip) {
                    $kutip = null;
                }

                continue;
            }

            if ($karakter === '"' || $karakter === "'" || $karakter === '`') {
                $kutip = $karakter;

                continue;
            }

            if ($karakter === '{') {
                $kedalaman++;

                continue;
            }

            if ($karakter === '}') {
                $kedalaman--;

                if ($kedalaman === 0) {
                    return substr($kode, $awalObjek, $i - $awalObjek + 1);
                }
            }
        }

        $this->fail('Blok useForm tidak tertutup dengan benar');
    }

    /**
     * Kunci properti pada tingkat teratas objek yang diberikan ke useForm.
     *
     * Diurai sungguhan (bukan tebakan): `current_stock: props.product?.x`
     * hanya berbeda dari `current_stock: 0` dalam hal apakah ada pembungkus
     * kondisional di sekitarnya — dan itu justru yang menentukan apakah field
     * itu ikut terkirim saat PATCH.
     *
     * @return array<int, string>
     */
    private function kunciTingkatAtas(string $objek): array
    {
        $kunci = [];
        $kedalaman = 0;
        $kutip = null;
        $panjang = strlen($objek);

        for ($i = 0; $i < $panjang; $i++) {
            $karakter = $objek[$i];

            if ($kutip !== null) {
                if ($karakter === '\\') {
                    $i++;

                    continue;
                }

                if ($karakter === $kutip) {
                    $kutip = null;
                }

                continue;
            }

            if ($karakter === '"' || $karakter === "'" || $karakter === '`') {
                $kutip = $karakter;

                continue;
            }

            if ($karakter === '{' || $karakter === '[' || $karakter === '(') {
                $kedalaman++;

                continue;
            }

            if ($karakter === '}' || $karakter === ']' || $karakter === ')') {
                $kedalaman--;

                continue;
            }

            // Key tingkat teratas: pengenal yang diikuti tanda titik dua, pada
            // kedalaman 1, dan bukan bagian dari ekspresi lain.
            if ($kedalaman !== 1 || ! preg_match('/[A-Za-z_$]/', $karakter)) {
                continue;
            }

            // Karakter sebelumnya harus berupa spasi, koma, atau '{'.
            $sebelumnya = $i > 0 ? $objek[$i - 1] : '';

            if (! in_array($sebelumnya, [' ', "\n", "\r", "\t", ',', '{'], true)) {
                continue;
            }

            // Baca nama pengenal.
            $nama = '';

            while ($i < $panjang && preg_match('/[A-Za-z0-9_$]/', $objek[$i])) {
                $nama .= $objek[$i];
                $i++;
            }

            $i--; // for akan menambah lagi

            // Harus diikuti tanda titik dua (bukan pemanggilan fungsi).
            $sisipan = ltrim(substr($objek, $i + 1));

            if (str_starts_with($sisipan, ':')) {
                $kunci[] = $nama;
            }
        }

        return $kunci;
    }

    public function test_current_stock_bukan_key_tingkat_atas(): void
    {
        $blok = $this->blokUseForm($this->sumber());
        $kunci = $this->kunciTingkatAtas($blok);

        $this->assertNotContains(
            'current_stock',
            $kunci,
            'current_stock tidak boleh jadi key tingkat atas di useForm(): '
            .'useForm().data() mengambil seluruh key, jadi field ini akan ikut terkirim '
            .'saat PATCH walaupun kolomnya disembunyikan di mode edit. Server lalu '
            .'menolaknya dengan aturan prohibited, dan klik "Simpan" tidak menyimpan apa pun. '
            .'Bungkus dengan spread kondisional, contoh: ...(props.product ? {} : { current_stock: 0 })',
        );
    }

    public function test_current_stock_masih_ada_di_blok_useform(): void
    {
        $blok = $this->blokUseForm($this->sumber());

        $this->assertStringContainsString(
            'current_stock',
            $blok,
            'current_stock harus tetap ada untuk mode create (stok pembuka)',
        );
    }

    public function test_key_wajib_ada_di_blok_useform(): void
    {
        $kunci = $this->kunciTingkatAtas($this->blokUseForm($this->sumber()));

        foreach (['sku', 'name', 'category_id', 'reorder_point'] as $wajib) {
            $this->assertContains($wajib, $kunci, "Key '{$wajib}' hilang dari useForm()");
        }
    }

    public function test_mode_edit_tidak_merender_input_stok(): void
    {
        $kode = $this->sumber();

        // Kolom stok awal hanya untuk create.
        $this->assertStringContainsString('v-if="!product"', $kode);
    }

    public function test_error_current_stock_punya_tempat_tampil_di_kedua_mode(): void
    {
        $kode = $this->sumber();

        // Count message: `form.errors.current_stock` pada blok create (v-if)
        // dan `errorFor('current_stock')` pada blok read-only (v-else).
        $this->assertStringContainsString(
            "errorFor('current_stock')",
            $kode,
            'Kolom stok harus menampilkan error-nya sendiri saat mode edit, '
            .'kalau tidak pesan validasi tidak terlihat di layar',
        );

        $this->assertGreaterThanOrEqual(
            2,
            substr_count($kode, "errorFor('current_stock')"),
            'error current_stock harus dirender di kedua mode (create & edit)',
        );
    }

    public function test_ada_banner_untuk_error_yang_tidak_punya_kolom(): void
    {
        $kode = $this->sumber();

        $this->assertStringContainsString('errorsTakTampil', $kode);
        $this->assertStringContainsString('Perubahan belum tersimpan', $kode);

        // Jaring pengaman harus benar-benar dirender.
        $this->assertMatchesRegularExpression(
            '/v-if="errorsTakTampil\.length"/',
            $kode,
            'Banner error harus punya kondisi v-if, kalau tidak tidak akan pernah tampil',
        );
    }
}
