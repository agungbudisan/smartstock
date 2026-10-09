<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Skala tipografi tidak boleh menyusut, dan tidak boleh kembali ke `px`.
 *
 * Dua aturan ini berasal dari satu keluhan yang sama: beberapa bagian aplikasi
 * terasa terlalu kecil dan kurang ramah bagi pengguna yang bermasalah dengan
 * penglihatannya.
 *
 * **Aturan rem.** Dengan `px`, mengatur "Font size" di pengaturan tampilan
 * browser -- langkah yang paling sering dilakukan pengguna yang matanya
 * bermasalah -- tidak mengubah apa pun di aplikasi ini. Dengan `rem`, seluruh
 * skala ikut mengikuti, termasuk pengatur ukuran teks di menu user.
 *
 * **Aturan batas bawah.** Skala lama turun ke 11px untuk `overline`, dan itu
 * dipakai untuk 44 header tabel -- bukan teks hiasan, tapi label kolom yang
 * harus dibaca operator untuk menemukan angka yang ia cari. Huruf besar di
 * 11px kehilangan ascender dan descender, jadi kata-kata terfragmentasi.
 *
 * Batas bawah di sini tidak diambil dari WCAG: WCAG tidak menetapkan ukuran
 * font minimum. Nilainya diambil dari apa yang dipakai system desain besar,
 * ditambah kenyataan bahwa aplikasi ini dipakai sepanjang hari di gudang.
 */
class TypographyScaleTest extends TestCase
{
    /**
     * Ukuran minimum per token, dalam rem.
     *
     * Token yang tidak disebut di sini tidak diperiksa -- menambah token baru
     * di bawah jangkauan ini akan lolos. Kalau itu terjadi, tambahkan ke sini
     * sekalian.
     *
     * @var array<string, float>
     */
    private const MINIMUM = [
        'overline' => 0.75,
        'caption' => 0.8125,
        'small' => 0.875,
        'body' => 1.0,
        'subhead' => 1.375,
    ];

    public function test_semua_ukuran_font_dalam_rem(): void
    {
        $skala = $this->skala();

        $this->assertNotEmpty($skala, 'Tidak ada token fontSize yang terbaca.');

        foreach ($skala as $token => $nilai) {
            $this->assertMatchesRegularExpression(
                '/^[\d.]+rem$/',
                $nilai,
                "Token `{$token}` memakai `{$nilai}`, bukan `rem`. Dengan `px`, "
                .'mengatur ukuran font di browser tidak mengubah apa pun di aplikasi '
                .'ini, dan pengatur ukuran teks di menu user ikut tidak berguna.',
            );
        }
    }

    public function test_ukuran_font_tidak_menurun_di_bawah_batas(): void
    {
        $skala = $this->skala();

        foreach (self::MINIMUM as $token => $minimum) {
            $this->assertArrayHasKey(
                $token,
                $skala,
                "Token `{$token}` hilang dari skala tipografi.",
            );

            $rem = (float) str_replace('rem', '', $skala[$token]);

            $this->assertGreaterThanOrEqual(
                $minimum,
                $rem,
                sprintf(
                    'Token `%s` sekarang %s (%dpx). Batas bawah yang disepakati '
                    .'%srem (%dpx) -- nilai di bawah itu terlalu kecil untuk teks '
                    .'yang harus dibaca bekerja, apalagi kalau huruf besarnya.',
                    $token,
                    $skala[$token],
                    $rem * 16,
                    $minimum,
                    $minimum * 16,
                ),
            );
        }
    }

    /**
     * Setiap token harus punya line-height.
     *
     * Ukuran yang naik tanpa line-height ikut naik akan membuat baris terasa
     * rapat: dua baris berdekatan tanpa jarak, dan baris tabel menjadi sempit.
     */
    public function test_setiap_token_punya_line_height(): void
    {
        foreach ($this->bagian('fontSize') as $token => $nilai) {
            $this->assertStringContainsString(
                'lineHeight',
                $nilai,
                "Token `{$token}` tidak punya lineHeight. Ukuran yang lebih besar "
                .'tanpa line-height yang ikut naik membuat baris terasa sempit.',
            );
        }
    }

    /**
     * Wadah konten harus tetap `px`, bukan ikut skala.
     *
     * Ini kebalikan dari aturan rem, dan sengaja. Kalau `maxWidth.container`
     * ikut `rem`, memperbesar teks akan ikut melebarkan wadah sehingga konten
     * terdorong keluar dari layar -- persis kebalikan dari yang diinginkan.
     */
    public function test_wadah_konten_tetap_px(): void
    {
        $maxWidth = $this->bagian('maxWidth');

        $this->assertArrayHasKey('container', $maxWidth, 'Token maxWidth.container hilang.');

        // Nilainya masih dibungkus tanda kutip di berkas konfigurasi.
        $nilai = trim($maxWidth['container'], "' ");

        $this->assertStringEndsWith(
            'px',
            $nilai,
            "Wadah konten bernilai {$nilai}, harusnya px. Kalau ikut skala rem, "
            .'memperbesar teks akan ikut melebarkan wadah dan mendorong konten '
            .'keluar dari layar.',
        );
    }

    /**
     * Tidak boleh ada ukuran font sebaris di template.
     *
     * Token satu-satunya yang pernah muncul di sini adalah badge notifikasi
     * yang memakai `text-[10px]` -- lebih kecil dari token terkecil pun. Ukuran
     * sebaris melewati daftar token, jadi batas minimum dan aturan rem di atas
     * tidak berlaku padanya.
     */
    public function test_tidak_ada_ukuran_font_sebaris_di_template(): void
    {
        $ditemukan = [];

        foreach ($this->seluruhVue() as $relatif => $isi) {
            preg_match_all('/(?:text|font)-\[([\d.]+)(px|rem)\]/', $isi, $m, PREG_SET_ORDER);

            foreach ($m as $cocok) {
                $ditemukan[] = "{$relatif}  {$cocok[0]}";
            }
        }

        $this->assertSame(
            [],
            $ditemukan,
            "Ukuran font sebaris di template:\n  ".implode("\n  ", $ditemukan)
            ."\n\nGunakan token skala tipografi. Ukuran sebaris melewati daftar "
            .'token, jadi batas minimum dan aturan rem tidak berlaku padanya.',
        );
    }

    /**
     * Pengatur ukuran teks harus mencakup nilai yang sama persis dengan CSS.
     *
     * Nilai yang ada di CSS tapi tidak di komponen menyisakan aturan mati;
     * nilai yang ada di komponen tapi tidak punya aturan membuat tombol yang
     * kelihatan berfungsi padahal tidak mengubah apa pun -- lebih buruk dari
     * tidak ada tombolnya.
     */
    public function test_pengatur_ukuran_teks_sejalan_dengan_css(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        preg_match_all("/data-ukuran-teks='([a-z-]+)'/", $css, $m);
        $dariCss = $m[1];

        $kontrol = (string) file_get_contents(resource_path('js/Components/TextSizeControl.vue'));

        preg_match_all("/nilai: '([a-z-]+)'/", $kontrol, $m2);
        $dariJs = $m2[1];

        $this->assertNotEmpty($dariCss, 'Tidak ada aturan pengatur ukuran teks di app.css.');
        $this->assertNotEmpty($dariJs, 'Tidak ada opsi pengatur ukuran teks di komponen.');

        // "normal" sengaja tidak punya aturan CSS: tanpa atribut, `html`
        // mengikuti ukuran bawaan browser apa adanya.
        $this->assertContains('normal', $dariJs, 'Opsi "normal" harus ada di komponen.');
        $this->assertNotContains(
            'normal',
            $dariCss,
            'Opsi "normal" TIDAK boleh punya aturan CSS. Menuliskannya akan menimpa '
            .'ukuran font bawaan browser yang sudah mungkin diatur pengguna sendiri.',
        );

        $hanyaCss = array_values(array_diff($dariCss, $dariJs));
        $this->assertSame([], $hanyaCss, 'Nilai ada di CSS tapi tidak di komponen: '.implode(', ', $hanyaCss));

        $hanyaJs = array_values(array_diff($dariJs, $dariCss, ['normal']));
        $this->assertSame([], $hanyaJs, 'Nilai ada di komponen tapi tidak punya aturan CSS: '.implode(', ', $hanyaJs));
    }

    /**
     * Preferensi ukuran teks harus dipasang sebelum halaman digambar.
     *
     * Kalau atributnya baru dipasang setelah Vue mount, aplikasi terlihat dalam
     * ukuran lama selama satu frame lalu melonjak -- dan itu terjadi setiap kali
     * halaman dibuka, untuk pengguna yang memakai ukuran besar setiap hari.
     */
    public function test_ukuran_teks_dipasang_sebelum_halaman_digambar(): void
    {
        $blade = (string) file_get_contents(resource_path('views/app.blade.php'));

        $posisiSkrip = strpos($blade, 'smartstock.ukuran-teks');
        $posisiVite = strpos($blade, '@vite');

        $this->assertNotFalse(
            $posisiSkrip,
            'app.blade.php tidak membaca ukuran teks dari localStorage.',
        );
        $this->assertNotFalse($posisiVite, 'app.blade.php tidak memanggil @vite.');
        $this->assertLessThan(
            $posisiVite,
            $posisiSkrip,
            'Skrip ukuran teks harus berada SEBELUM @vite. Kalau sesudahnya, halaman '
            .'sudah mulai digambar dengan ukuran lama.',
        );
    }

    /**
     * Komponen pengatur harus dipakai di sidebar DAN drawer.
     *
     * Keduanya menampilkan menu user yang sama. Kalau hanya satu yang memakai
     * pengatur, pengguna ponsel tidak punya akses ke pengaturan yang sama dengan
     * pengguna desktop -- dan tidak ada yang melihat perbedaannya.
     */
    public function test_pengatur_ukuran_teks_ada_di_sidebar_dan_drawer(): void
    {
        $layout = (string) file_get_contents(resource_path('js/Layouts/AppLayout.vue'));

        $this->assertSame(
            2,
            substr_count($layout, '<TextSizeControl'),
            'Pengatur ukuran teks harus muncul di menu sidebar dan di drawer ponsel.',
        );
    }

    // -----------------------------------------------------------------
    // Pembantu
    // -----------------------------------------------------------------

    /**
     * @return array<string, string> token => nilai ukuran
     */
    private function skala(): array
    {
        $hasil = [];

        foreach ($this->bagian('fontSize') as $token => $baris) {
            if (preg_match("/\[\s*'([^']+)'/", $baris, $m)) {
                $hasil[$token] = $m[1];
            }
        }

        return $hasil;
    }

    /**
     * Ambil satu blok konfigurasi dari `tailwind.config.js`.
     *
     * @return array<string, string> token => nilai mentah tanpa koma akhir
     */
    private function bagian(string $nama): array
    {
        $hasil = [];

        foreach ($this->barisDalamBlok($nama) as $baris) {
            if (! preg_match("/^\s*([a-zA-Z][\w-]*):\s*(.+)$/", $baris, $m)) {
                continue;
            }

            // Baris pembuka blok juga cocok dengan polanya, tapi nilainya hanya
            // `{` -- itu nama blok, bukan token di dalamnya.
            if (trim($m[2]) === '{') {
                continue;
            }

            $hasil[$m[1]] = rtrim(trim($m[2]), ',');
        }

        return $hasil;
    }

    /**
     * Baris-baris di dalam satu blok konfigurasi.
     *
     * File itu JavaScript, bukan JSON, dan tidak ada parsernya di sini. Yang
     * dibutuhkan memang cuma nama token dan nilai mentah pertamanya.
     *
     * @return array<int, string>
     */
    private function barisDalamBlok(string $nama): array
    {
        $teks = (string) file_get_contents(base_path('tailwind.config.js'));

        $awal = strpos($teks, "$nama: {");
        if ($awal === false) {
            return [];
        }

        // Hitung kurung kurawal supaya blok anak tidak ikut tercampur: `fontSize`
        // berada di dalam `theme` yang punya banyak properti lain.
        $tingkat = 0;
        $akhir = $awal;
        $panjang = strlen($teks);

        for ($i = $awal + strlen($nama) + 2; $i < $panjang; $i++) {
            if ($teks[$i] === '{') {
                $tingkat++;
            } elseif ($teks[$i] === '}') {
                $tingkat--;

                if ($tingkat === 0) {
                    $akhir = $i;
                    break;
                }
            }
        }

        return explode("\n", substr($teks, $awal, $akhir - $awal));
    }

    /**
     * @return array<string, string> path relatif => isi file
     */
    private function seluruhVue(): array
    {
        $hasil = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $hasil[str_replace(resource_path('js').DIRECTORY_SEPARATOR, '', $file->getPathname())]
                    = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($hasil);

        return $hasil;
    }
}
