/**
 * Periksa setiap `.vue` di `resources/js`: nama-nama yang dipakai di template
 * harus benar-benar terikat di `<script setup>`.
 *
 * Kenapa perlu alat ini, kalau `npm run build` sudah ada?
 *
 * Build Vite hanya Mentranspilasi. Ia tidak menyelesaikan nama yang dipakai
 * template terhadap isi `<script setup>`, dan tidak melempar error kalau ada
 * yang tidak ketemu. Identifier yang tidak dikenal dibuang menjadi
 * `_ctx.nama` -- dibiarkan Compile, tidak dikomentari, dan baru meledak saat
 * komponen dirender di peramban.
 *
 * kasus nyata yang membuat alat ini lahir: `Movements.vue` punya docblock
 * `/**` yang tidak pernah ditutup, sehingga satu blok kode lama ikut tertelan.
 * Empat `function` setelahnya berhenti jadi binding tingkat atas -- compiler
 * tetap "berhasil", build tetap hijau, test PHP tetap hijau, dan halaman
 * Pergerakan Stok hanya menampilkan layar putih dengan
 * `TypeError: pesanBaris is not a function`.
 *
 * Cara kerja: `compileScript()` memberi `bindings` (apa yang diekspor setup),
 * lalu `compileTemplate()` dikompilasi dengan `bindingMetadata` itu. Apa pun
 * yang tidak ada di `bindings` akan muncul sebagai `_ctx.nama` di hasil
 * compile -- persis bentuk yang gagal saat runtime.
 *
 * Dipakai oleh `tests/Feature/VueTemplateBindingTest.php`.
 * Jalankan manual: `node tests/js/check-vue-bindings.mjs`
 */

import fs from 'node:fs';
import path from 'node:path';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';

/**
 * Nama yang memang DILEWATI ke `$setup`, karena disuntikkan plugin global.
 *
 * `route` datang dari `ZiggyVue` yang didaftarkan di `resources/js/app.js`;
 * `$page` datang dari Inertia. Keduanya hidup di `globalProperties`, jadi
 * `_ctx.route` menyelesaikannya dengan benar. Tanpa daftar ini, enamel
 * halaman Auth akan dilaporkan sebagai bug padahal tidak.
 */
const GLOBAL_PLEUGIN = new Set(['route', '$page', '$inertia', '$attrs', '$slots']);

function vueFiles(dir, out = []) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);

        if (entry.isDirectory()) {
            vueFiles(full, out);
        } else if (entry.name.endsWith('.vue')) {
            out.push(full);
        }
    }

    return out;
}

export function periksa(root = 'resources/js') {
    const masalah = [];

    for (const file of vueFiles(root)) {
        const source = fs.readFileSync(file, 'utf8');
        const { descriptor, errors } = parse(source, { filename: file });

        if (errors.length) {
            masalah.push({ file, jenis: 'parse-gagal', pesan: errors.map((e) => e.message).join(' | ') });

            continue;
        }

        // Halaman berbasis Options API (atau tanpa template) tidak punya
        // `<script setup>` untuk diperiksa -- biarkan.
        if (!descriptor.scriptSetup || !descriptor.template) {
            continue;
        }

        let script;

        try {
            script = compileScript(descriptor, { id: 'pemeriksaan' });
        } catch (e) {
            masalah.push({ file, jenis: 'compile-gagal', pesan: e.message });

            continue;
        }

        const template = compileTemplate({
            source: descriptor.template.content,
            filename: file,
            id: 'pemeriksaan',
            compilerOptions: {
                bindingMetadata: script.bindings,
                mode: 'module',
                prefixIdentifiers: true,
            },
        });

        const jatuh = [...new Set(
            [...template.code.matchAll(/_ctx\.([A-Za-z_$][\w$]*)/g)].map((m) => m[1]),
        )].filter((nama) => !GLOBAL_PLEUGIN.has(nama));

        if (jatuh.length) {
            masalah.push({
                file,
                jenis: 'binding-hilang',
                pesan: `tidak terikat di <script setup>, akan jadi undefined: ${jatuh.join(', ')}`,
            });
        }
    }

    return masalah;
}

// Dipakai langsung dari command line.
if (process.argv[1] && import.meta.url.endsWith(path.basename(process.argv[1]))) {
    const masalah = periksa();

    for (const m of masalah) {
        console.log(`[${m.jenis}] ${m.file}\n    ${m.pesan}`);
    }

    if (masalah.length) {
        console.log(`\n${masalah.length} berkas bermasalah.`);
        process.exit(1);
    }

    console.log('Semua .vue: tidak ada binding template yang hilang.');
}