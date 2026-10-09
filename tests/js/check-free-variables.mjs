/**
 * Perangkap variabel bebas di blok `<script setup>`.
 *
 * Vite hanya mentranspilasi. Identifier yang tidak dikenal di `<script setup>`
 * dibuang jadi referensi global tanpa peringatan, lalu meledak sebagai
 * `ReferenceError` ketika komponen di-mount -- gejalanya halaman putih dengan
 * satu galat di konsol, bukan kegagalan build.
 *
 * Dua kelas kerusakan yang sudah benar-benar terjadi di proyek ini:
 *
 *  1. `Movements.vue` -- satu blok `/**` yang tidak tertutup menelan empat
 *     `function`. Gejalanya tidak nyambung: galat menunjuk baris 120 sementara
 *     kerusakannya di baris 72. Yang menangkap adalah `check-vue-bindings.mjs`.
 *
 *  2. `Reorder.vue` -- `usePage` dihapus dari blok `import`, tapi
 *     `const page = usePage()` masih ada. Rollup menganggap `usePage` sebagai
 *     global yang tebakan, jadi build tetap hijau. Yang capturing hanya
 *     membuka halamannya di peramban.
 *
 * Yang kedua TIDAK tertangkap `check-vue-bindings.mjs`, karena skrip itu hanya
 * memeriksa nama yang muncul di template. Yang bermasalah ada di sisi skrip.
 * Keduanya sekarang dijaga.
 *
 * Bunyi:ssyntax parse tidak diurus di sini. Berkas yang tidak bisa dibaca
 * dianggap satu kegagalan, bukan dilewati diam-diam.
 */

import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const AKAR = process.argv[2] ?? 'resources/js';

/*
 * Yang boleh muncul tanpa diimpor.
 *
 * RUJUKANNYA `resources/js/app.js`: hanya `ZiggyVue` (menyediakan `route`)
 * dan plugin Inertia (menyediakan `$page`) yang memasang global. Itu saja.
 *
 * Daftar ini pernah memuat lebih dari itu -- `ref`, `computed`, `watch`,
 * `usePage`, `useForm`, `router`, dan lain-lain -- dengan alasan "dipakai di
 * template". Itu keliru dan berbahaya: seluruhnya WAJIB diimpor, dan
 * membiarkannya lolos di sini membuat penjaga buta tepat pada bug yang
 * seharusnya ia tangkap. `ref`, `computed`, dan `usePage` semuanya
 * `ReferenceError` kalau impornya hilang.
 *
 * Makro kompilator `<script setup>` (defineProps, defineEmits, ...) memang
 * tidak perlu impor. Hanya itu, bersama bawaan bahasa dan browser.
 */
const BAWAAN = new Set([
    // Bahasa
    'Array', 'ArrayBuffer', 'BigInt', 'BigInt64Array', 'Boolean', 'DataView',
    'Date', 'Error', 'EvalError', 'Float32Array', 'Float64Array', 'Function',
    'Infinity', 'Int16Array', 'Int32Array', 'Int8Array', 'Intl', 'JSON', 'Map',
    'Math', 'NaN', 'Number', 'Object', 'Promise', 'Proxy', 'RangeError',
    'ReferenceError', 'Reflect', 'RegExp', 'Set', 'String', 'Symbol',
    'SyntaxError', 'TypeError', 'URIError', 'URL', 'URLSearchParams',
    'Uint16Array', 'Uint32Array', 'Uint8Array', 'Uint8ClampedArray', 'WeakMap',
    'WeakSet',
    // Browser
    'alert', 'cancelAnimationFrame', 'clearInterval', 'clearTimeout', 'confirm',
    'console', 'document', 'fetch', 'globalThis', 'history', 'isFinite', 'isNaN',
    'localStorage', 'location', 'navigator', 'parseFloat', 'parseInt', 'prompt',
    'queueMicrotask', 'requestAnimationFrame', 'sessionStorage',
    'setInterval', 'setTimeout', 'structuredClone', 'window',
    // Makro kompilator <script setup> -- satu-satunya yang tidak perlu impor
    'defineEmits', 'defineExpose', 'defineModel', 'defineOptions', 'defineProps',
    'defineSlots', 'withDefaults',
    // Dipasang sebagai global oleh app.js
    'route', '$page',
]);

/*
 * Keyword yang boleh diikuti kurung buka. `if (...)` bukan pemanggilan fungsi,
 * dan memverifikasi `if` sebagai "tak dikenal" hanya menambah derau.
 */
const KEYWORD = new Set([
    'if', 'for', 'while', 'switch', 'catch', 'return', 'typeof', 'instanceof',
    'new', 'function', 'await', 'of', 'in', 'do', 'else', 'yield', 'delete',
    'void', 'throw', 'case', 'default',
]);

/** Buang komentar dan literal string, sisanya baru dipindai. */
function TanpaKomentarDanString(kode) {
    let hasil = '';
    let i = 0;

    while (i < kode.length) {
        const c = kode[i];
        const d = kode[i + 1];

        if (c === '/' && d === '/') {
            while (i < kode.length && kode[i] !== '\n') i++;
            continue;
        }

        if (c === '/' && d === '*') {
            i += 2;
            while (i < kode.length && !(kode[i] === '*' && kode[i + 1] === '/')) i++;
            i += 2;
            continue;
        }

        if (c === "'" || c === '"' || c === '`') {
            const kutip = c;
            i++;
            while (i < kode.length) {
                if (kode[i] === '\\') {
                    i += 2;
                    continue;
                }
                if (kode[i] === kutip) {
                    i++;
                    break;
                }
                // Interpolasi di template literal berisi kode nyata. Isi
                // `${...}` ikut dipindai, jadi bukan dilewati.
                if (kutip === '`' && kode[i] === '$' && kode[i + 1] === '{') {
                    let dalam = 1;
                    i += 2;
                    while (i < kode.length && dalam > 0) {
                        if (kode[i] === '{') dalam++;
                        else if (kode[i] === '}') dalam--;
                        if (dalam === 0) {
                            i++;
                            break;
                        }
                        hasil += kode[i];
                        i++;
                    }
                    // Pemisah wajib. Tanpa ini dua interpolasi bersebelahan
                    // digabung jadi satu nama: `a${x}b${y}` jadi `axby`, dan
                    // pemindai melaporkan variabel yang tidak pernah ada.
                    hasil += ' ';
                    continue;
                }
                i++;
            }
            hasil += ' ';
            continue;
        }

        hasil += c;
        i++;
    }

    return hasil;
}

/** Nama yang dideklarasikan, diimpor, atau menjadi parameter di blok skrip. */
function NamaTersedia(kode) {
    const nama = new Set();

    const tambah = (teks) => {
        for (let n of teks.split(',')) {
            // Buang sisa pola destructure dan nilai bawaan parameter.
            n = n
                .trim()
                .replace(/^\.\.\./, '')
                .replace(/[{}[\]]/g, '')
                .split('=')[0]
                .trim();

            if (/^[A-Za-z_$][\w$]*$/.test(n)) nama.add(n);
        }
    };

    // import Default, { A, B as C }, * as NS  dari  '...'
    for (const m of kode.matchAll(/\bimport\b([^'"]*?)from\b/gs)) {
        const isi = m[1];

        for (const kurung of isi.matchAll(/\{([^}]*)\}/g)) tambah(kurung[1]);

        tambah(isi.replace(/\{[^}]*\}/g, ',').replace(/\*\s+as\s+/g, ','));
    }

    // const|let|var, termasuk destructure dan array
    for (const m of kode.matchAll(/\b(?:const|let|var)\s+([^=;\n]+?)\s*(?:=|;|$)/g)) tambah(m[1]);

    // Parameter panah fungsi bertanda kurung: (a, b) =>  dan  ({ x, y }) =>
    for (const m of kode.matchAll(/\(([^()]*)\)\s*=>/g)) tambah(m[1]);

    // Parameter panah fungsi tanpa kurung: a =>
    for (const m of kode.matchAll(/(^|[^\w$.])([A-Za-z_$][\w$]*)\s*=>/g)) nama.add(m[2]);

    // Parameter function biasa: function nama(a, b)
    for (const m of kode.matchAll(/\bfunction\b[^(]*\(([^()]*)\)/g)) tambah(m[1]);

    // catch (e)
    for (const m of kode.matchAll(/\bcatch\s*\(([^()]*)\)/g)) tambah(m[1]);

    // Method shorthand di dalam object literal: `get() {`
    //
    // Tidak bisa dibedakan dari pemanggilan fungsi secara tekstual, tapi
    // baris yang diawali `nama(` hampir selalu definisi method, bukan
    // pemanggilan. Menandainya sebagai deklarasi hanya mengurangi positives
    // palsu; nama yang lolos di sini sudah ditutup pemeriksa template.
    for (const m of kode.matchAll(/(?:^|\n)\s*([A-Za-z_$][\w$]*)\s*\(/g)) nama.add(m[1]);

    // function nama, async function nama, function* nama
    for (const m of kode.matchAll(/\bfunction\s*\*?\s*([A-Za-z_$][\w$]*)/g)) nama.add(m[1]);

    // class Nama
    for (const m of kode.matchAll(/\bclass\s+([A-Za-z_$][\w$]*)/g)) nama.add(m[1]);

    return nama;
}

/**
 * Nama yang dipanggil sebagai fungsi, dan nama yang jadi awal rantai properti.
 *
 * Dua bentuk itu yang menghasilkan `ReferenceError` saat komponen di-mount.
 * Identifier lain yang berdiri sendiri (mis. label, argumen) bisa jadi variabel
 * global yang sah, jadi tidak dilaporkan.
 */
function NamaDipakai(kode) {
    const dipakai = new Set();

    for (const m of kode.matchAll(/(^|[^\w$.])([A-Za-z_$][\w$]*)\s*\(/g)) {
        dipakai.add(m[2]);
    }

    for (const m of kode.matchAll(/(^|[^\w$.?])([A-Za-z_$][\w$]*)\s*\./g)) {
        dipakai.add(m[2]);
    }

    return dipakai;
}

function SkripSetup(isi) {
    const cocok = isi.match(/<script\s+setup[^>]*>([\s\S]*?)<\/script>/i);

    return cocok ? cocok[1] : null;
}

function BerkasVue(dir) {
    const keluar = [];

    for (const entri of readdirSync(dir)) {
        const penuh = join(dir, entri);
        const info = statSync(penuh);

        if (info.isDirectory()) {
            keluar.push(...BerkasVue(penuh));
        } else if (entri.endsWith('.vue')) {
            keluar.push(penuh);
        }
    }

    return keluar;
}

const masalah = [];

for (const berkas of BerkasVue(AKAR)) {
    const isi = readFileSync(berkas, 'utf8');
    const skrip = SkripSetup(isi);

    if (skrip === null) continue;

    const bersih = TanpaKomentarDanString(skrip);
    const tersedia = NamaTersedia(bersih);
    const dipakai = NamaDipakai(bersih);

    for (const nama of [...dipakai].sort()) {
        if (tersedia.has(nama) || BAWAAN.has(nama) || KEYWORD.has(nama)) continue;

        // Nama yang mirip atribut bawaan DOM ($page, $slots) tetap dilaporkan:
        // di `<script setup>` memang tidak tersedia.
        masalah.push({ berkas: relative(process.cwd(), berkas), nama });
    }
}

if (masalah.length === 0) {
    console.log(`Semua .vue: tidak ada variabel bebas di <script setup>.`);
    process.exit(0);
}

console.log('');

for (const { berkas, nama } of masalah) {
    console.log(`  ${berkas}: '${nama}' tidak diimpor dan tidak dideklarasikan`);
}

console.log(`\n${masalah.length} variabel bebas. Gejalanya: ReferenceError saat halaman dibuka.`);
process.exit(1);