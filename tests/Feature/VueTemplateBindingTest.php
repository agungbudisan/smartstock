<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Setiap nama yang dipakai di template `.vue` harus benar-benar terikat di
 * `<script setup>`.
 *
 * Kenapa perlu test di PHP kalau `npm run build` sudah ada?
 *
 * Build Vite hanya mentranspilasi. Ia TIDAK menyelesaikan nama yang dipakai
 * template terhadap isi `<script setup>`, dan tidak melempar error kalau ada
 * yang tidak ketemu. Identifier yang tidak dikenal dibuang menjadi `_ctx.nama`:
 * dikompilasi dengan rapi, tidak dikomentari, dan baru meledak ketika
 * komponen dirender di peramban.
 *
 * Yang sudah lolos tanpa terdeteksi: `Movements.vue` punya docblock `/**` yang
 * tak pernah ditutup, sehingga satu blok kode lama ikut tertelan dan empat
 * `function` berhenti jadi binding tingkat atas. Akibatnya `npm run build`
 * hijau, 430 test PHP hijau, dan halaman Pergerakan Stok hanya menampilkan
 * layar putih dengan `TypeError: pesanBaris is not a function`.
 *
 * Jadi test ini menutup kelas bug yang TIDAK bisa ditutup oleh test PHP yang
 * membaca teks `.vue` sebagai string -- file itu masih "berisi" kodenya,
 * hanya saja posisinya salah.
 *
 * Test SKIP kalau Node tidak ada, supaya suite tetap jalan di mesin tanpa
 * frontend toolchain.
 */
class VueTemplateBindingTest extends TestCase
{
    private const SKRIP = 'tests/js/check-vue-bindings.mjs';

    public function test_tidak_ada_binding_template_yang_hilang(): void
    {
        $node = $this->node();

        if ($node === null) {
            $this->markTestSkipped('Node tidak tersedia; pemeriksaan binding template dilewati.');
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
            "Ada identifier di template .vue yang tidak terikat di <script setup>:\n".$keluar
            ."\n\nBuild Vite tidak akan menangkap ini -- ia hanya mentranspilasi. "
            ."Layar putih dengan 'is not a function' adalah gejalanya."
        );

        $this->assertStringContainsString(
            'tidak ada binding template yang hilang',
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
