<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Source code hanya boleh memuat karakter non-ASCII yang ada di daftar ini.
 *
 * Dua kelas kerusakan yang keduanya sudah benar-benar terjadi di proyek ini,
 * dan keduanya lolos semua pemeriksaan yang ada:
 *
 * **Mojibake.** Tanda em-dash (U+2014) yang byte UTF-8-nya dibaca sebagai
 * Latin-1 menjadi tiga karakter: U+00E2, U+20AC, U+201D. Di browser tampil
 * Latin-1 menjadi tiga karakter beruntun yang tampil sebagai tiga huruf
 * aneh di depan nama produk pada daftar dropdown. Yang membuatnya lolos adalah
 * tidak pernah masuk ke sana.
 *
 * **Karakter asing dari terjemahan mesin.** Satu comment berisi kata
 * Mandarin, satu berisi kata Spanyol, dan satu berisi potongan dari comment
 * berbahasa Mandarin. Semuanya terlihat "wajar" kalau hanya dipindai satu
 * per satu, dan semuanya menghasilkan teks yang tidak bisa dibaca.
 *
 * Karena itu daftar di bawah bersifat TERTUTUP: karakter apa pun yang tidak
 * ada di sini dianggap rusak, termasuk yang belum pernah kita temui. Daftar
 * putih lebih jujur daripada daftar hitam -- daftar hitam hanya menahan yang
 * sudah pernah salah, sedangkan yang belum pernah salah akan tetap bisa masuk.
 */
class SourceEncodingTest extends TestCase
{
    /**
     * Karakter non-ASCII yang sah, dengan alasannya.
     *
     * Setiap entri wajib punya alasan. Tanpa alasan, entri itu cuma izin
     * diam-diam untuk mengizinkan karakter aneh masuk ke mana saja.
     *
     * @var array<string, string>
     */
    private const SAH = [
        '00B1' => 'tanda plus-minus, dipakai pada "berbeda +-2%" di AGENTS.md',
        '00B2' => 'pangkat dua, dipakai pada "R^2" di notasi regresi linear',
        '00B7' => 'tengah titik, pemisah bagian tooltip pada BarChart',
        '00D7' => 'tanda kali, dipakai pada "3,26 x 4" di ringkasan seeder',
        '0233' => 'y dengan macron, dipakai pada notasi rerata "y-bar"',
        '0304' => 'macron combining, dipakai pada notasi rerata "x-bar"',
        '03A3' => 'sigma, dipakai pada notasi regresi linear',
        '2013' => 'en dash, dipisah dari em dash agar keduanya tidak tertukar',
        '2014' => 'em dash, dipakai sebagai tanda baca dalam prosa Indonesia',
        '2026' => 'ellipsis, untuk memotong teks panjang',
        '2192' => 'panah kanan, dipakai pada "a -> b"',
        '2194' => 'panah dua arah, dipakai pada "kiri <-> kanan"',
        '2705' => 'centang hijau, penanda tabel RBAC di AGENTS.md',
        '274C' => 'silang merah, penanda tabel RBAC di AGENTS.md',
    ];

    /** Ekstensi berkas yang dipindai. */
    private const EKSTENSI = ['php', 'vue', 'css', 'js', 'md'];

    /** Akar yang dipindai, relatif terhadap proyek. */
    private const AKAR = ['app', 'config', 'database', 'routes', 'resources', 'tests'];

    public function test_tidak_ada_karakter_asing_di_seluruh_source(): void
    {
        $bermasalah = [];

        foreach ($this->seluruhBerkas() as $relatif => $path) {
            $isi = (string) file_get_contents($path);

            foreach (preg_split('/\R/', $isi) ?: [] as $nomor => $baris) {
                foreach (preg_split('//u', $baris, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $karakter) {
                    $kode = strtoupper(str_pad(dechex(mb_ord($karakter)), 4, '0', STR_PAD_LEFT));

                    if (mb_ord($karakter) < 128 || isset(self::SAH[$kode])) {
                        continue;
                    }

                    $bermasalah[] = sprintf(
                        '%s:%d  U+%s  %s',
                        $relatif,
                        $nomor + 1,
                        $kode,
                        $this->cuplikan($baris),
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $bermasalah,
            "Karakter non-ASCII yang tidak ada di daftar SAH:\n  "
            .implode("\n  ", array_slice($bermasalah, 0, 40))
            ."\n\nKalau ini memang sengaja, tambahkan ke SourceEncodingTest::SAH "
            .'BESERTA alasannya. Kalau bukan, sumbernya rusak -- biasanya karena '
            .'tool pengedit menulis UTF-8 yang dibaca sebagai Latin-1, atau teks '
            .'yang masuk dari terjemahan mesin.',
        );
    }

    /**
     * Pemeriksaan khusus mojibake, dengan pesan yang menyebut gejalanya.
     *
     * Test utama sudah menangkapnya, tapi pesannya generik. Yang ini
     * menunjuk tepat tanda tangannya supaya mudah dikenali.
     */
    public function test_tidak_ada_urutan_mojibake(): void
    {
        // U+2014 yang ditulis sebagai UTF-8 (E2 80 94) lalu dibaca Latin-1.
        $polanya = '/(?:\x{00E2}\x{20AC}\x{201D}|\x{00E2}\x{20AC}\x{2013}|\x{00C3}\x{00A2}\x{00A2})/u';

        $ditemukan = [];

        foreach ($this->seluruhBerkas() as $relatif => $path) {
            $isi = (string) file_get_contents($path);

            if (preg_match_all($polanya, $isi, $m, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($m[0] as [$potongan, $posisi]) {
                    $baris = substr_count(substr($isi, 0, $posisi), "\n") + 1;
                    $ditemukan[] = "{$relatif}:{$baris}  ".json_encode($potongan, JSON_UNESCAPED_UNICODE);
                }
            }
        }

        $this->assertSame(
            [],
            $ditemukan,
            "Ditemukan urutan mojibake (tanda baca UTF-8 yang dibaca Latin-1):\n  "
            .implode("\n  ", array_slice($ditemukan, 0, 20)),
        );
    }

    /**
     * Daftar periksa harus benar-benar dipakai.
     *
     * Kalau satu entri tidak pernah muncul di mana pun, daftar itu sudah basi:
     * ia menahan karakter yang tidak lagi ada tanpa memberi manfaat, dan
     * pemeriksaan berhenti berlaku untuk karakter itu.
     */
    public function test_setiap_karakter_yang_diizinkan_benar_benar_dipakai(): void
    {
        $teks = '';

        foreach ($this->seluruhBerkas() as $path) {
            $teks .= file_get_contents($path);
        }

        foreach (self::SAH as $kode => $alasan) {
            $karakter = mb_chr((int) hexdec($kode), 'UTF-8');

            $this->assertStringContainsString(
                $karakter,
                $teks,
                "U+{$kode} ada di daftar SAH tapi tidak dipakai di mana pun. "
                .'Entri yang basi menahan karakter tanpa memberi manfaat.',
            );
        }
    }

    // -----------------------------------------------------------------
    // Pembantu
    // -----------------------------------------------------------------

    /**
     * @return array<string, string> path relatif => path absolut
     */
    private function seluruhBerkas(): array
    {
        $hasil = [];

        // AGENTS.md ada di akar proyek, bukan di salah satu direktori di atas.
        // Dia tetap dipindai: tanda plus-minus dan emoji RBAC hanya hidup di sana.
        $dokumen = base_path('AGENTS.md');
        if (is_file($dokumen)) {
            $hasil['AGENTS.md'] = $dokumen;
        }

        foreach (self::AKAR as $akar) {
            $direktori = base_path($akar);

            if (! is_dir($direktori)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($direktori, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), self::EKSTENSI, true)) {
                    continue;
                }

                $hasil[str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname())] = $file->getPathname();
            }
        }

        ksort($hasil);

        return $hasil;
    }

    private function cuplikan(string $baris): string
    {
        $baris = trim(preg_replace('/\s+/', ' ', $baris) ?? $baris);

        return mb_strlen($baris) > 90 ? mb_substr($baris, 0, 90).'...' : $baris;
    }
}
