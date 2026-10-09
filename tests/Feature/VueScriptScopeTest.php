<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Setiap nama yang dipakai di `<script setup>` harus benar-benar diimpor atau
 * dideklarasikan -- atau memang global.
 *
 * Ini kakak kelas dari `VueTemplateBindingTest`, dan menutup sisi yang belum
 * ditutup: pemeriksa itu hanya menandai nama yang muncul di TEMPLATE, sedangkan
 * bug yang satu ini ada di dalam SKRIP.
 *
 * Yang sudah lolos tanpa terdeteksi: `Reorder.vue` punya `const page =
 * usePage()` tapi `usePage` sudah dihapus dari blok `import`. Rollup melihat
 * nama yang tidak dikenal dan memperlakukannya sebagai global tebakan, jadi
 * `npm run build` hijau. Halaman Reorder baru meledak saat dibuka Manager,
 * dengan `ReferenceError: usePage is not defined` di konsol -- layar putih.
 *
 * Bedanya dari kasus `Movements.vue` yang ditutup `VueTemplateBindingTest`: di
 * sana empat `function` hilang dan ikatannya hilang dari template. Di sini
 * templatenya utuh; yang hilang adalah impor di sisi skrip. Pemeriksa lama
 * melihat template yang baik dan-largestabaatzvooraldiam.
 *
 * Yang membuat penjaga ini tidak berguna justru daftar "bawaan"-nya. Versi
 * pertama memuat `ref`, `computed`, `watch`, `usePage`, `useForm`, dan
 * `router` dengan alasan "dipakai di template". Semuanya WAJIB diimpor, dan
 * membiarkannya lolos membuat penjaga ini buta tepat pada bug yang seharusnya
 * ia tangkap. Rujukan yang benar cuma `resources/js/app.js`: global yang
 * dipasang aplikasi cuma `route` (dari ZiggyVue) dan `$page` (dari plugin
 * Inertia).
 *
 * Test SKIP kalau Node tidak ada, supaya suite tetap jalan di mesin tanpa
 * frontend toolchain.
 */
class VueScriptScopeTest extends TestCase
{
    private const SKRIP = 'tests/js/check-free-variables.mjs';

    public function test_tidak_ada_variabel_bebas_di_script_setup(): void
    {
        $node = $this->node();

        if ($node === null) {
            $this->markTestSkipped('Node tidak tersedia; pemeriksaan cakupan skrip dilewati.');
        }

        $proses = new Process(
            [$node, self::SKRIP],
            base_path(),
            null,
            null,
            120,
        );

        $proses->run();

        $keluar = trim($proses->getOutput()."\n".$proses->getErrorOutput());

        $this->assertSame(
            0,
            $proses->getExitCode(),
            "Ada nama di <script setup> .vue yang tidak diimpor dan tidak dideklarasikan:\n"
            .$keluar
            ."\n\nBuild Vite tidak akan menangkap ini. Rollup memperlakukan nama "
            .'yang tidak dikenal sebagai global tebakan, lalu ReferenceError baru '
            .'muncul saat halaman dibuka di peramban.'
        );

        $this->assertStringContainsString(
            'tidak ada variabel bebas',
            $keluar,
        );
    }

    /**
     * Lokasi `node`, atau NULL kalau tidak terpasang.
     */
    private function node(): ?string
    {
        if (! is_file(base_path(self::SKRIP))) {
            $this->markTestSkipped('Skrip pemeriksaan tidak ada: '.self::SKRIP);
        }

        $coba = ['node', 'node.exe'];

        foreach ($coba as $executable) {
            $proses = Process::fromShellCommandline($executable.' --version');
            $proses->run();

            if ($proses->isSuccessful()) {
                return $executable;
            }
        }

        return null;
    }
}
