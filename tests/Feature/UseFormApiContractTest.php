<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Kontrak pemanggilan `useForm()` dari `@inertiajs/vue3`.
 *
 * Dua bug di halaman produk & pergerakan stok berasal dari salah memahami
 * API ini, dan keduanya HANYA muncul di browser sebagai error JavaScript —
 * test HTTP tidak pernah menangkapnya. Jadi di sini perakitannya diperiksa
 * dari sumber, bukan dari hasil request.
 *
 * Kontrak yang dipegang (lihat `node_modules/@inertiajs/vue3/dist/index.esm.js`,
 * fungsi `post`/`patch`/`submit` dan `UseFormUtils.parseSubmitArguments`):
 *
 *   form.post(url, options)             → 2 argumen
 *   form.patch(url, options)            → 2 argumen
 *   form.submit(method, url, options)   → 3 argumen, WAJIB method literal
 *
 * Data payload SELALU diambil dari objek form lewat `this.data()`. Tidak ada
 * parameter data terpisah, dan tidak ada `form.toJSON()` di versi ini.
 */
class UseFormApiContractTest extends TestCase
{
    /**
     * Semua berkas Vue di frontend.
     *
     * @return array<int, string>
     */
    private function vueFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Hitung jumlah argumen di tingkat teratas pada suatu daftar argumen JS.
     *
     * Sadar tanda kurung, kurung siku, kurung kurawal, string, dan komentar —
     * supaya koma di dalam `{ a: 1, b: 2 }` tidak ikut terhitung.
     *
     * `$daftar` adalah isi SETELAH `(` pemanggilan, jadi level nest-nya sudah
     * 1 dari awal. Kalau dimulai dari 0, koma di dalam objek literal akan
     * salah terbaca sebagai pemisah argumen.
     *
     * @return array{0: array<int, string>, 1: bool} [argumen, ada yang belum tertutup]
     */
    private function pisahArgumen(string $daftar): array
    {
        $argumen = [];
        $buffer = '';
        $kutip = null;
        $kedalaman = 1;
        $panjang = strlen($daftar);

        for ($i = 0; $i < $panjang; $i++) {
            $karakter = $daftar[$i];
            $berikutnya = $daftar[$i + 1] ?? '';

            // Lewati komentar baris.
            if ($kutip === null && $karakter === '/' && $berikutnya === '/') {
                while ($i < $panjang && $daftar[$i] !== "\n") {
                    $i++;
                }

                continue;
            }

            // Lewati komentar blok.
            if ($kutip === null && $karakter === '/' && $berikutnya === '*') {
                $i += 2;

                while ($i < $panjang && ! ($daftar[$i] === '*' && ($daftar[$i + 1] ?? '') === '/')) {
                    $i++;
                }

                $i++;

                continue;
            }

            if ($kutip !== null) {
                $buffer .= $karakter;

                if ($karakter === '\\') {
                    $buffer .= $berikutnya;
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
                $buffer .= $karakter;

                continue;
            }

            if ($karakter === '(' || $karakter === '[' || $karakter === '{') {
                $kedalaman++;
                $buffer .= $karakter;

                continue;
            }

            if ($karakter === ')' || $karakter === ']' || $karakter === '}') {
                $kedalaman--;

                if ($kedalaman === 0) {
                    // Penutup daftar argumen: argumen terakhir yang belum
                    // dipisah koma tetap dihitung.
                    if (trim($buffer) !== '') {
                        $argumen[] = trim($buffer);
                    }

                    return [$argumen, false];
                }

                $buffer .= $karakter;

                continue;
            }

            if ($karakter === ',' && $kedalaman === 1) {
                $argumen[] = trim($buffer);
                $buffer = '';

                continue;
            }

            $buffer .= $karakter;
        }

        if (trim($buffer) !== '') {
            $argumen[] = trim($buffer);
        }

        return [$argumen, true];
    }

    /**
     * Cari semua pemanggilan `form.<method>(...)` beserta argumennya.
     *
     * @return array<int, array{file: string, method: string, args: array<int, string>, line: int}>
     */
    private function pemanggilanForm(): array
    {
        $hasil = [];
        $metode = ['post', 'patch', 'put', 'delete', 'get', 'submit'];

        foreach ($this->vueFiles() as $file) {
            $kode = (string) file_get_contents($file);

            foreach ($metode as $method) {
                $pola = '/\bform\s*(?:as\s+\w+\s*)?\.\s*'.$method.'\s*\(/';

                if (! preg_match_all($pola, $kode, $cocok, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                foreach ($cocok[0] as [$potongan, $offset]) {
                    $buka = $offset + strlen($potongan) - 1;
                    [$args, $tidakTutup] = $this->pisahArgumen(substr($kode, $buka + 1));

                    if ($tidakTutup) {
                        continue;
                    }

                    $hasil[] = [
                        'file' => $file,
                        'method' => $method,
                        'args' => $args,
                        'line' => substr_count(substr($kode, 0, $offset), "\n") + 1,
                    ];
                }
            }
        }

        return $hasil;
    }

    private function label(array $panggilan): string
    {
        return str_replace(
            base_path().DIRECTORY_SEPARATOR,
            '',
            $panggilan['file'],
        ).':'.$panggilan['line'].' form.'.$panggilan['method'].'()';
    }

    // -----------------------------------------------------------------

    public function test_frontend_memang_menggunakan_useform(): void
    {
        $this->assertNotEmpty(
            $this->pemanggilanForm(),
            'Kalau test ini menemukan 0 pemanggilan, berarti tidak ada form Inertia untuk diperiksa',
        );
    }

    public function test_tidak_ada_form_tojson(): void
    {
        $pelanggaran = [];

        foreach ($this->vueFiles() as $file) {
            $kode = (string) file_get_contents($file);

            // Hanya baris kode, bukan komentar — komentar memang menyebut
            // toJSON() sebagai peringatan.
            $tanpaKomentar = preg_replace(['#//[^\n]*#', '#/\*.*?\*/#s'], '', $kode) ?? $kode;

            if (str_contains($tanpaKomentar, 'toJSON(')) {
                $pelanggaran[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
            }
        }

        $this->assertSame(
            [],
            $pelanggaran,
            'useForm versi ini tidak punya toJSON(). Pakai form.data() atau kirim objek form apa adanya.',
        );
    }

    public function test_helper_form_tidak_boleh_lebih_dari_dua_argumen(): void
    {
        $pelanggaran = [];

        foreach ($this->pemanggilanForm() as $panggilan) {
            // `submit` memang 3 argumen; helper-nya hanya 2.
            if ($panggilan['method'] === 'submit') {
                continue;
            }

            if (count($panggilan['args']) > 2) {
                $pelanggaran[] = $this->label($panggilan).' mengirim '
                    .count($panggilan['args']).' argumen';
            }
        }

        $this->assertSame(
            [],
            $pelanggaran,
            "post/patch/put/delete hanya menerima (url, options).\nPayload diambil otomatis dari objek form.\n"
            .implode("\n", $pelanggaran),
        );
    }

    public function test_submit_harus_menerima_method_url_options(): void
    {
        $metodeHttp = ['get', 'post', 'put', 'patch', 'delete'];
        $pelanggaran = [];

        foreach ($this->pemanggilanForm() as $panggilan) {
            if ($panggilan['method'] !== 'submit') {
                continue;
            }

            $args = $panggilan['args'];
            $jumlah = count($args);
            $pertama = $args[0] ?? '';

            // Argumen pertama WAJIB method. Nilai yang diterima:
            //   - string literal HTTP method        → form.submit('patch', url)
            //   - member expression berakhiran
            //     "method"                          → form.submit(props.method, url)
            //
            // Bentuk terakhir diterima karena method sering datang dari prop
            // komponen yang dipakai ulang. Tapi `props.submitUrl` tetap
            // ditolak: itu bug yang nyata — Inertia akan menganggap URL
            // sebagai HTTP method dan melempar "Ue[h] is not a function".
            $bagian = explode('.', $pertama);
            $segmenTerakhir = trim(end($bagian));

            $benar = $jumlah >= 2
                && (in_array(trim($pertama, "'\""), $metodeHttp, true) || $segmenTerakhir === 'method');

            if (! $benar) {
                $pelanggaran[] = $this->label($panggilan)
                    .' argumen pertama bukan HTTP method yang valid → '.$pertama;
            }
        }

        $this->assertSame([], $pelanggaran, implode("\n", $pelanggaran));
    }

    public function test_helper_form_selalu_mengirim_url(): void
    {
        $pelanggaran = [];

        foreach ($this->pemanggilanForm() as $panggilan) {
            if ($panggilan['method'] === 'submit') {
                continue;
            }

            if (($panggilan['args'][0] ?? '') === '') {
                $pelanggaran[] = $this->label($panggilan).' tanpa URL';
            }
        }

        $this->assertSame([], $pelanggaran, implode("\n", $pelanggaran));
    }
}
