<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

/**
 * Form produk dipakai ulang untuk halaman Create dan Edit.
 * `submitUrl` menentukan tujuan POST/PATCH.
 */
const props = defineProps({
    product: { type: Object, default: null },
    categories: { type: Array, required: true },
    // Dipakai untuk kolom "Pemasok Stok Awal" pada mode create saja.
    suppliers: { type: Array, default: () => [] },
    // Boleh mengisi stok awal? Hanya Admin Gudang. Manager boleh menulis master
    // data, tapi mencatat pergerakan stok bukan haknya -- jadi kolomnya
    // disembunyikan, bukan sengaja dibuat error.
    bolehCatatStok: { type: Boolean, default: true },
    // Saran SKU per kategori, dihitung server sekali saat halaman dirender.
    saranSku: { type: Object, default: () => ({}) },
    submitUrl: { type: String, required: true },
    method: { type: String, default: 'post' },
});

/**
 * `current_stock` HANYA ikut sebagai field form saat MEMBUAT produk.
 *
 * Penting: `useForm().data()` mengambil seluruh key dari objek form - bukan
 * hanya field yang dirender di template. Kalau `current_stock` tetap ada di
 * sini, field itu ikut terkirim saat PATCH walaupun kolomnya disembunyikan
 * (v-if="!product"), dan server menolaknya dengan aturan `prohibited`.
 * Akibatnya klik "Simpan" di halaman edit tidak menyimpan apa pun.
 *
 * `supplier_id` juga hanya untuk mode create - mencatat asal-usul stok
 * pembuka. Opsional, karena stok awal dari hasil hitung fisik sering tidak
 * punya dokumen pembelian.
 *
 * Ketiga: field ini HILANG TOTAL kalau `bolehCatatStok` false, bukan cuma
 * disembunyikan di template. Kalau tetap ada di objek form, Manager mengirim
 * `current_stock: 0` yang sebenarnya sah -- tapi kalau form nanti salah
 * menampilkan kolom itu lagi, angka yang diketik ikut terkirim tanpa pernah
 * ada di layar. Menghapus key-nya membuat "tidak terlihat" dan "tidak
 * terkirim" tidak mungkin terpisah.
 */
const form = useForm({
    sku: props.product?.sku ?? '',
    name: props.product?.name ?? '',
    category_id: props.product?.category_id ?? '',
    reorder_point: props.product?.reorder_point ?? 0,

    ...(props.product || !props.bolehCatatStok ? {} : { current_stock: 0, supplier_id: '' }),
});

/** Field yang punya kolom + pesan error sendiri di layar. */
const FIELD_TERSEDIA = ['sku', 'name', 'category_id', 'reorder_point', 'current_stock', 'supplier_id'];

/**
 * Error untuk field yang tidak punya kolom di halaman ini (misal
 * `current_stock` saat mode edit). Tanpa banner ini, kegagalan simpan
 * terjadi tanpa keterangan apa pun.
 */
const errorsTakTampil = computed(() =>
    Object.entries(form.errors).filter(([field]) => !FIELD_TERSEDIA.includes(field)),
);

/**
 * SKU terakhir yang diisi OTOMATIS oleh form ini.
 *
 *Variabel itulah yang membuat auto-isi aman: kalau isian SKU saat ini
 * sama dengan saran sebelumnya, berarti operator belum menyentuhnya, jadi
 * boleh diganti. Kalau berbeda, operator sudah mengetik sendiri dan kita TIDAK
 * boleh menimpa -- memindahkan ketikan orang tanpa diminta adalah kehilangan
 * pekerjaan, bukan bantuan.
 */
let saranTerakhir = null;

watch(
    () => form.category_id,
    (kategoriBaru) => {
        // Hanya di mode create. Di mode edit, SKU sudah pernah terbit --
        // berubah sendiri karena kategori diganti akan memutus cetakan label
        // dan history purchase order yang sudah keluar.
        if (props.product) return;

        const saran = props.saranSku[String(kategoriBaru)];

        if (!saran) {
            // Kategori tanpa prefix, atau belum ada kategori yang dipilih.
            // Kosongkan HANYA kalau isiannya masih milik form ini.
            if (form.sku === saranTerakhir) form.sku = '';
            saranTerakhir = null;
            return;
        }

        if (form.sku && form.sku !== saranTerakhir) return;

        saranTerakhir = saran;
        form.sku = saran;
    },
);

/**
 * Kategori terpilih, untuk menampilkan prefix-nya sebagai petunjuk.
 */
const kategoriTerpilih = computed(() =>
    props.categories.find((c) => String(c.id) === String(form.category_id)),
);

/**
 * Saran untuk kategori terpilih,|null. `null` kalau kategori tidak punya
 * prefix, supaya petunjuk tidak menjanjikan pola yang tidak ada.
 */
const saranSekarang = computed(() => props.saranSku[String(form.category_id)] ?? null);

function submit() {
    // `useForm().submit()` harus dipanggil dengan (method, url, options).
    //
    // Data form dikirim OTOMATIS dari objek form — tidak ada parameter data
    // terpisah. Memanggil `submit(url, { method })` membuat Inertia
    // menganggap URL itu sebagai HTTP method, sehingga lempar
    // "Ue[h] is not a function".
    form.submit(props.method, props.submitUrl, { preserveScroll: true });
}

const errorFor = (field) => form.errors[field];
</script>

<template>
    <AppLayout :title="product ? 'Ubah Produk' : 'Tambah Produk'">
        <div class="mb-8">
            <p class="overline">Master Data</p>
            <h1 class="mt-1 text-section">{{ product ? 'Ubah Produk' : 'Tambah Produk' }}</h1>
        </div>

        <form class="card max-w-2xl space-y-5 p-6" @submit.prevent="submit">
            <!--
                Jaring pengaman: kalau ada error untuk field yang tidak punya
                kolom di halaman ini (mis. current_stock saat mode edit),
                tampilkan di sini. Tanpa ini, klik "Simpan" bisa gagal tanpa
                keterangan apa pun.
            -->
            <div
                v-if="errorsTakTampil.length"
                class="rounded-input border border-danger/30 bg-red-50 px-4 py-3"
                role="alert"
            >
                <p class="text-small font-medium text-danger">Perubahan belum tersimpan.</p>
                <ul class="mt-1 space-y-0.5">
                    <li v-for="[field, pesan] in errorsTakTampil" :key="field" class="text-small text-danger">
                        {{ pesan }}
                    </li>
                </ul>
            </div>

            <div>
                <label class="label" for="sku">SKU</label>
                <input id="sku" v-model="form.sku" type="text" class="input font-mono" required />
                <p class="mt-1 text-caption text-content-muted">
                    <!--
                        Petunjuk hanya muncul kalau kategori yang dipilih punya
                        prefix. Kategori tanpa prefix itu kondisi yang sah, jadi
                        tidak ada janji pola yang tidak ada.
                    -->
                    <template v-if="saranSekarang">
                        Terisi otomatis dari prefix
                        <span class="font-mono text-content-secondary">{{ kategoriTerpilih?.sku_prefix }}</span>.
                        Boleh diubah.
                    </template>
                    <template v-else-if="kategoriTerpilih && !kategoriTerpilih.sku_prefix">
                        Kategori ini belum punya prefix SKU, jadi tidak ada pola yang disarankan.
                    </template>
                    <template v-else>
                        Kode barang. Sebaiknya mengikuti pola kategori, mis. ELK-001.
                    </template>
                </p>
                <p v-if="errorFor('sku')" class="mt-1 text-caption text-danger">{{ errorFor('sku') }}</p>
            </div>

            <div>
                <label class="label" for="name">Nama Produk</label>
                <input id="name" v-model="form.name" type="text" class="input" required />
                <p v-if="errorFor('name')" class="mt-1 text-caption text-danger">{{ errorFor('name') }}</p>
            </div>

            <div>
                <label class="label" for="category">Kategori</label>
                <select id="category" v-model="form.category_id" class="input">
                    <option value="">Tanpa kategori</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <p v-if="errorFor('category_id')" class="mt-1 text-caption text-danger">{{ errorFor('category_id') }}</p>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <!--
                    Stok awal hanya boleh diisi saat MEMBUAT produk, dan nilainya
                    dicatat sebagai Inbound pembuka supaya buku besar bisa
                    menjelaskan asal-usulnya. Saat edit, field ini sengaja tidak
                    ada: server juga menolak field `current_stock` dengan aturan
                    `prohibited`, jadi mengetik angka di sini tidak akan diam-diam
                    merusak stok tanpa jejak audit.
                -->
                <div v-if="!product">
                    <!--
                        Kolom stok awal hanya untuk peran yang berwenang mencatat
                        pergerakan. Manager membuat produk dengan stok 0, lalu Admin
                        Gudang mencatat barang masuknya lewat halaman Entri.

                        Kolom disembunyikan, BUKAN dibuat error: Kolom yang tampil
                        tapi selalu ditolak adalah jebakan yang persis baru saja
                        dibongkar di halaman pergerakan stok.
                    -->
                    <template v-if="bolehCatatStok">
                        <label class="label" for="stock">Stok Awal</label>
                    <input
                        id="stock"
                        v-model.number="form.current_stock"
                        type="number"
                        min="0"
                        class="input"
                        required
                    />
                    <p class="mt-1 text-caption text-content-muted">
                        Dicatat sebagai Inbound pembuka, jadi muncul di Buku Besar dan Jejak Audit.
                    </p>
                    <p v-if="errorFor('current_stock')" class="mt-1 text-caption text-danger">
                        {{ errorFor('current_stock') }}
                    </p>

                    <!--
                        Asal-usul stok pembuka. Opsional karena stok awal dari
                        hasil hitung fisik memang sering tanpa dokumen, tapi
                        kalau ada, sebaiknya dicatat sekarang: barang yang
                        masuk tanpa jejak pemasok sulit ditelusuri belakangan.
                    -->
                    <div class="mt-4">
                        <label class="label" for="supplier">Pemasok Stok Awal (opsional)</label>
                        <select id="supplier" v-model="form.supplier_id" class="input">
                            <option value="">Tanpa pemasok (stok hasil hitung)</option>
                            <option v-for="s in suppliers" :key="s.id" :value="s.id">
                                {{ s.name }}
                            </option>
                        </select>
                        <p class="mt-1 text-caption text-content-muted">
                            Kalau diisi, Inbound pembuka dan jejak audit akan menyebut nama pemasok ini.
                        </p>
                        <p v-if="errorFor('supplier_id')" class="mt-1 text-caption text-danger">
                            {{ errorFor('supplier_id') }}
                        </p>
                    </div>
                    </template>

                    <!--
                        Kenapa kolomnya tidak ada, dijelaskan di sini. Manager yang
                        mengisi form ini tanpa penjelasan akan menyimpulkan formnya
                        rusak, lalu mencoba mengisinya lewat halaman lain.
                    -->
                    <div v-else class="rounded-input bg-surface-muted px-3.5 py-3">
                        <p class="label">Stok Awal</p>
                        <p class="text-small text-content-secondary">
                            Produk baru dibuat dengan stok 0. Pencatatan barang masuk
                            dilakukan Admin Gudang lewat halaman Pergerakan Stok, supaya
                            buku besar dan jejak audit tetap mencatat siapa yang menerima
                            barang.
                        </p>
                    </div>
                </div>

                <div v-else>
                    <p class="label">Stok Saat Ini</p>
                    <p class="rounded-input bg-surface-muted px-3.5 py-2.5 font-display text-subhead">
                        {{ product.current_stock?.toLocaleString('id-ID') }}
                    </p>
                    <p class="mt-1 text-caption text-content-muted">
                        Stok hanya berubah lewat entri Inbound, Outbound, atau Stock Opname — bukan dari
                        form ini, supaya buku besar dan jejak audit selalu cocok.
                    </p>
                    <p v-if="errorFor('current_stock')" class="mt-1 text-caption text-danger">
                        {{ errorFor('current_stock') }}
                    </p>
                </div>

                <div>
                    <label class="label" for="reorder">Batas Aman (Reorder Point)</label>
                    <input id="reorder" v-model.number="form.reorder_point" type="number" min="0" class="input" required />
                    <p class="mt-1 text-caption text-content-muted">Sistem memberi peringatan saat stok menyentuh angka ini.</p>
                    <p v-if="errorFor('reorder_point')" class="mt-1 text-caption text-danger">
                        {{ errorFor('reorder_point') }}
                    </p>
                </div>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit" class="btn-primary" :disabled="form.processing">
                    {{ form.processing ? 'Menyimpan...' : 'Simpan' }}
                </button>
                <a href="/products" class="btn-secondary">Batal</a>
            </div>
        </form>
    </AppLayout>
</template>
