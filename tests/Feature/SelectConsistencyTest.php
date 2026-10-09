<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Tampilan dropdown harus seragam: chevron tidak boleh menutupi teks, dan
 * kosakata opsi default harus punya aturan yang bisa ditebak.
 *
 * Gejala yang dikeluhkan: keterangan default yang panjang seperti
 * "-- tanpa pemasok (stok hasil hitung) --" masuk ke bawah chevron dan
 * terpotong, sementara yang pendek kebetulan tidak -- sehingga kelihatan acak
 * dari satu dropdown ke dropdown lain. Penyebabnya `.input` memberi
 * `padding-right` 14px, sedangkan chevron bawaan browser memakan sekitar
 * 20-28px.
 *
 * Kosakata opsi default punya tiga makna berbeda yang masih tercampur:
 *
 *   - `Semua produk`       tidak ada filter, buka semua
 *   - `-- pilih produk --`  belum ada yang dipilih, wajib diisi
 *   - `Tanpa kategori`      memang boleh kosong, dan itu jawaban sah
 *
 * Semuanya bernilai `value=""`, jadi bentuknya yang harus membedakan -- kalau
 * tidak, pembaca tidak tahu mana yang berarti "belum diisi" dan mana yang
 * berarti "sengaja dikosongkan".
 *
 * Test ini membaca `.vue` sebagai teks, seperti `MovementModeSwitchTest` dan
 * `SkuAutoFillTest`. Yang diperiksa adalah KEHADIRAN atribut literal, bukan
 * logika. Untuk itu cara ini paling jujur: `npm run build` hanya mentranspilasi,
 * jadi class yang hilang dari satu `<select>` tidak akan ketahuan sampai ada yang
 * melukainya di layar.
 */
class SelectConsistencyTest extends TestCase
{
    /** Awalan untuk "tidak ada batasan filter". */
    private const BENTUK_SEMUA = 'Semua ';

    /** Awalan untuk "belum dipilih, wajib diisi" -- satu-satunya yang memakai tanda hubung. */
    private const BENTUK_PILIH = '-- pilih ';

    /** Awalan untuk "boleh kosong, dan itu jawaban sah". */
    private const BENTUK_TANPA = 'Tanpa ';

    /**
     * Setiap `<select>` harus memakai class `input`.
     *
     * Perbaikan chevron ditulis sebagai `select.input`, jadi dropdown yang tidak
     * memakai `.input` tidak ikut diperbaiki -- dan akan terlihat persis seperti
     * dropdown yang sudah dibetulkan, sehingga masalah yang sama muncul lagi.
     */
    public function test_setiap_dropdown_memakai_class_input(): void
    {
        $select = $this->semuaSelect();

        $this->assertNotEmpty($select, 'Harus ada dropdown di aplikasi.');

        foreach ($select as [$file, $tag]) {
            $this->assertMatchesRegularExpression(
                '/\bclass="[^"]*\binput\b[^"]*"/',
                $tag,
                "Dropdown di {$file} tidak memakai class `input`. Perbaikan chevron "
                .'hanya berlaku untuk `select.input`, jadi tanpa itu dropdown ini '
                .'akan tampil berbeda dari yang lain.',
            );
        }
    }

    public function test_aturan_css_dropdown_hanya_menghormati_input(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString(
            'select.input',
            $css,
            'Aturan chevron harus tetap `select.input` supaya dropdown ikut menerima '
            .'gaya dasar `.input` sekaligus.',
        );

        $this->assertStringContainsString(
            'appearance-none',
            $css,
            'Tanpa `appearance: none` chevron bawaan browser tetap muncul di samping '
            .'chevron milik kita -- dua tanda panah, dan yang bawaan tetap menabrak teks.',
        );

        $this->assertMatchesRegularExpression(
            '/select\.input\s*\{[^}]*\bpr-(\d+)/',
            $css,
            'Chevron butuh ruang kanan yang dijamin secara eksplisit; andalkan padding '
            .'bawaan `.input` (14px) adalah penyebab teks tertimpa.',
        );

        preg_match('/select\.input\s*\{[^}]*\bpr-(\d+)/', $css, $cocok);
        $ruang = (int) $cocok[1] * 4;

        $this->assertGreaterThan(
            28,
            $ruang,
            "Chevron hanya punya {$ruang}px ruang kanan; chevron browser memakan "
            .'sekitar 20-28px. Kalau ruangnya tidak lebih dari itu, teks panjang akan '
            .'masih menabrak.',
        );
    }

    /**
     * Opsi default harus mengikuti tiga kosakata yang disepakati.
     */
    public function test_opsi_default_memakai_kosakata_yang_konsisten(): void
    {
        $bentuk = [
            self::BENTUK_SEMUA => [],
            self::BENTUK_PILIH => [],
            self::BENTUK_TANPA => [],
        ];

        foreach ($this->semuaVue() as $file => $vue) {
            preg_match_all('/<option\s+value=""\s*>([^<]*)</', $vue, $m);

            foreach ($m[1] as $teks) {
                $teks = trim($teks);
                $ditemukan = null;

                foreach (array_keys($bentuk) as $awal) {
                    if (str_starts_with($teks, $awal)) {
                        $ditemukan = $awal;
                        break;
                    }
                }

                $this->assertNotNull(
                    $ditemukan,
                    "Opsi default \"{$teks}\" di {$file} tidak mengikuti kosakata yang "
                    .'disepakati. Gunakan "Semua ..." untuk filter, "-- pilih ..." untuk '
                    .'yang wajib diisi, atau "Tanpa ..." untuk yang boleh kosong.',
                );

                $bentuk[$ditemukan][] = "{$file}: {$teks}";
            }
        }

        // Ketiga bentuk harus benar-benar dipakai. Kalau satu tidak pernah muncul,
        // aturannya belum pernah teruji -- dan test yang menguji bentuk yang tidak
        // terjadi tetap hijau.
        foreach ($bentuk as $awal => $contoh) {
            $this->assertNotEmpty(
                $contoh,
                "Tidak ada opsi default memakai bentuk \"{$awal}...\"; aturannya belum pernah teruji.",
            );
        }

        // "Tanpa ..." tidak boleh naik-turun pagarnya dengan tanda hubung -- kalau
        // tidak ia tertukar dengan "-- pilih ...", dan perbedaan "boleh kosong"
        // vs "wajib diisi" hilang tepat di tempat paling penting.
        foreach ($bentuk[self::BENTUK_TANPA] as $contoh) {
            $this->assertStringStartsNotWith(
                '--',
                $contoh,
                "Bentuk \"Tanpa ...\" tidak perlu tanda hubung: {$contoh}.",
            );
        }
    }

    /**
     * Tidak boleh ada class `select` terpisah.
     *
     * Kalau suatu saat dropdown ditulis `class="select"`, aturan itu hilang
     * sama sekali: `select.input` tidak akan cocok. Class yang mati diam-diam
     * lebih buruk daripada tidak ada.
     */
    public function test_tidak_ada_class_select_terpisah(): void
    {
        foreach ($this->semuaVue() as $file => $vue) {
            $this->assertDoesNotMatchRegularExpression(
                '/class="[^"]*\bselect\b/',
                $vue,
                "{$file} memakai class `select`. Yang berlaku adalah `select.input`; "
                .'dropdown yang ditulis `class="select"` tidak akan mendapat perbaikan '
                .'chevron sama sekali.',
            );
        }
    }

    /**
     * Komponen yang menggantikan `<select>` harus tetap punya chevron sendiri.
     *
     * `SearchableSelect` memakai `<input type="text">` dengan `<svg>` buatan,
     * jadi ia tidak termasuk aturan `select.input`. Kalau chevronnya dihapus,
     * dropdown itu terlihat seperti kolom teks biasa -- dan tidak ada yang bisa
     * dibandingkan dengan dropdown lain tanpa membuka DevTools.
     */
    public function test_searchable_select_memiliki_chevron_sendiri(): void
    {
        $path = resource_path('js/Components/SearchableSelect.vue');

        $this->assertFileExists($path, 'SearchableSelect.vue tidak ada.');

        $vue = $this->tanpaKomentar((string) file_get_contents($path));

        $this->assertMatchesRegularExpression(
            '/m6 9 6 6 6-6/',
            $vue,
            'SearchableSelect harus punya chevron sendiri; tanpa itu ia tidak bisa '
            .'dibedakan dari kolom teks biasa.',
        );
    }

    // -----------------------------------------------------------------
    // Pembantu
    // -----------------------------------------------------------------

    /**
     * @return array<string, string> path relatif => isi file tanpa komentar
     */
    private function semuaVue(): array
    {
        $hasil = [];

        foreach ($this->seluruhBerkasVue() as $path) {
            $hasil[str_replace(resource_path('js').DIRECTORY_SEPARATOR, '', $path)]
                = $this->tanpaKomentar((string) file_get_contents($path));
        }

        return $hasil;
    }

    /**
     * Buang komentar sebelum isi file dipindai.
     *
     * Tanpa ini, penyebutan `<select>` di dalam docblock ikut terhitung sebagai
     * elemen. Itu bukan ketelitian kecil: docblock yang menjelaskan kenapa sebuah
     * komponen dibuat justru akan membuat test ini melaporkan barang yang tidak
     * pernah dirender -- dan perbaikannya bisa lolos karena tidak ada yang
     * mengira komentar itu sumber masalahnya.
     *
     * Dua bentuk yang dipakai di proyek ini: komentar blok JavaScript
     * dan komentar template. Bentuk blok ditulis sebagai garis miring bintang,
     * lalu bintang garis miring, supaya penutupnya tidak ikut menutup docblock ini.
     * docblock yang memakai bentuk itu untuk menyebut elemen HTML.
     */
    private function tanpaKomentar(string $teks): string
    {
        $blok = preg_replace('#/\*.*?\*/#s', '', $teks) ?? $teks;

        return preg_replace('#<!--.*?-->#s', '', $blok) ?? $blok;
    }

    /**
     * Semua tag pembuka `<select ...>` di aplikasi.
     *
     * @return array<int, array{0: string, 1: string}> pasangan [file, tag pembuka]
     */
    private function semuaSelect(): array
    {
        $hasil = [];

        foreach ($this->semuaVue() as $file => $vue) {
            // Tag `<select>` sering melintasi beberapa baris, jadi modifier `s`
            // wajib -- tanpa itu yang ke potong hanya baris pertamanya dan
            // atribut `class` bisa saja ada di baris berikutnya.
            preg_match_all('/<select\b[^>]*>/s', $vue, $m);

            foreach ($m[0] as $tag) {
                $hasil[] = [$file, $tag];
            }
        }

        return $hasil;
    }

    /**
     * @return array<int, string>
     */
    private function seluruhBerkasVue(): array
    {
        $hasil = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $hasil[] = $file->getPathname();
            }
        }

        sort($hasil);

        return $hasil;
    }
}
