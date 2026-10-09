<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Tabel Buku Besar Stok.
 *
 * Dulu juga dipakai di `Inventory/Movements` di bawah form entri. Sekarang
 * halaman itu tidak punya tabel sama sekali -- buku besar punya FILTERNYA
 * sendiri di `Inventory/Ledger`, jadi menyalin tabel tanpa filter berarti
 * menampilkan 20 baris terbaru apa adanya. Duplikasi data yang tampil di
 * dua halaman pasti berbeda begitu ada perbaikan di salah satunya.
 *
 * Filter di sini, bukan di halaman induk.
 *
 * Kemampuan filter sebenarnya sudah ada di `MovementController` (produk, jenis,
 * tanggal, ukuran halaman) dan bekerja lewat query string sejak dulu. Yang
 * tidak ada adalah kontrolnya. Untuk halaman milik Manager ini menentukan:
 * 900+ baris tanpa pencarian sama saja tidak bisa dipakai.
 */
const props = defineProps({
    movements: { type: Object, required: true },
    filters: { type: Object, required: true },

    // Dibutuhkan untuk dropdown filter produk.
    products: { type: Array, required: true },

    // Label jenis + warna chip. Dikirim controller supaya enum tetap punya
    // satu sumber kebenaran.
    movementTypes: { type: Array, required: true },
});

const emit = defineEmits(['change']);

const refs = {
    product_id: props.filters.product_id ?? '',
    movement_type: props.filters.movement_type ?? '',
    movement_date: props.filters.movement_date ?? '',
    per_page: props.filters.per_page ?? 20,
};

/**
 * Daftar hasil saat filter berubah.
 *
 * Yang dipertahankan: HANYA filter yang diisi. Meng-return ulang filter kosong
 * saat admin mengubah satu dropdown akan membuang pencarian yang sedang
 * diketik -- dan operator yang belum sempat menekan tombol kehilangan kerjaannya.
 */
function terapkan() {
    const query = {};

    for (const [kunci, nilai] of Object.entries(refs)) {
        if (nilai !== '' && nilai !== null && nilai !== undefined) {
            query[kunci] = nilai;
        }
    }

    emit('change', query);
}

function bersihkan() {
    refs.product_id = '';
    refs.movement_type = '';
    refs.movement_date = '';
    refs.per_page = 20;

    terapkan();
}

// Filter bisa datang dari URL (halaman lain menautkan ke sana, atau user
// menekan tombol back). Form lokal harus ikut menyesuaikan supaya yang
// ditampilkan bukan filter yang sudah tidak berlaku.
watch(
    () => props.filters,
    (baru) => {
        refs.product_id = baru.product_id ?? '';
        refs.movement_type = baru.movement_type ?? '';
        refs.movement_date = baru.movement_date ?? '';
        refs.per_page = baru.per_page ?? 20;
    },
    { deep: true }
);

const tipeOpsi = computed(() => props.movementTypes);

/**
 * Warna chip per jenis pergerakan.
 *
 * Dipakai gaya tampilan, jadi ia milik komponen ini -- bukan dikirim dari
 * server. Enum `MovementType` sudah punya `label()` di PHP; warna adalah
 * keputusan desain antarmuka dan tidak punya alasan bisnis.
 */
const chipTone = {
    inbound: 'chip-success',
    outbound: 'chip-warning',
    adjustment: 'chip-primary',
};
</script>

<template>
    <div>
        <div class="border-b border-hairline px-6 py-4">
            <h2 class="text-subhead">Buku Besar Stok</h2>
            <p class="mt-0.5 text-small text-content-secondary">
                Jumlah kolom delta di bawah sama dengan stok saat ini untuk tiap produk.
            </p>
        </div>

        <!--
            Filter memakai form + submit, bukan nyalakan filter saat mengetik.
            Alasannya: setiap perubahan filter adalah kueri baru ke seluruh
            900+ baris ledger. Mengetik "Kabel" akan menembak lima permintaan
            dan hanya respons terakhir yang dipakai. Server tetap bekerja,
            tapi operator melihat daftar berkedip.
        -->
        <form
            class="grid gap-3 border-b border-hairline bg-surface-muted px-6 py-4 sm:grid-cols-2 lg:grid-cols-5"
            @submit.prevent="terapkan"
        >
            <div class="lg:col-span-2">
                <label class="label" for="ledger-product">Produk</label>
                <select id="ledger-product" v-model="refs.product_id" class="input">
                    <option value="">Semua produk</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">
                        {{ p.sku }} — {{ p.name }}
                    </option>
                </select>
            </div>

            <div>
                <label class="label" for="ledger-type">Jenis</label>
                <select id="ledger-type" v-model="refs.movement_type" class="input">
                    <option value="">Semua jenis</option>
                    <option v-for="t in tipeOpsi" :key="t.value" :value="t.value">
                        {{ t.label }}
                    </option>
                </select>
            </div>

            <div>
                <label class="label" for="ledger-date">Tanggal</label>
                <input id="ledger-date" v-model="refs.movement_date" type="date" class="input" />
            </div>

            <div>
                <label class="label" for="ledger-per-page">Baris per halaman</label>
                <select id="ledger-per-page" v-model="refs.per_page" class="input">
                    <option :value="20">20</option>
                    <option :value="50">50</option>
                    <option :value="100">100</option>
                </select>
            </div>

            <div class="flex items-end gap-2 lg:col-span-5">
                <button type="submit" class="btn-primary btn-sm">Terapkan</button>
                <button type="button" class="btn-ghost btn-sm" @click="bersihkan">Bersihkan</button>
                <span class="ml-auto text-caption text-content-muted">
                    {{ movements.total }} baris
                </span>
            </div>
        </form>

        <p v-if="!movements.data.length" class="px-6 py-10 text-center text-small text-content-muted">
            Belum ada pergerakan stok.
        </p>

        <!--
            Tabel melebar digulir horizontal, bukan dipotong. Tanpa ini,
            `.card` yang `overflow-hidden` memotong kolom yang tidak muat dan
            datanya hilang tanpa jejak.

            `v-else` menempel di `<div>` ini, bukan di `<table>`: Vue mensyaratkan
            `v-if` dan `v-else` bersebelahan tanpa elemen lain di antaranya, dan
            yang menegakkannya adalah `npm run build`.
        -->
        <div v-else class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-surface-muted">
                    <tr class="text-left">
                        <th class="px-3 py-3 sm:px-5 text-overline">Waktu</th>
                        <th class="px-3 py-3 sm:px-5 text-overline">Jenis</th>
                        <th class="px-3 py-3 sm:px-5 text-overline">Produk</th>
                        <th class="px-3 py-3 sm:px-5 text-overline text-right">Delta</th>
                        <th class="px-3 py-3 sm:px-5 text-overline">Dicatat oleh</th>
                        <th class="px-3 py-3 sm:px-5 text-overline">Ref / Pemasok</th>
                    </tr>
                </thead>

                <tbody>
                    <tr v-for="row in movements.data" :key="row.id" class="table-row">
                        <td class="px-3 py-3 text-caption text-content-secondary sm:px-5">
                            {{ new Date(row.movement_date).toLocaleString('id-ID') }}
                        </td>

                        <td class="px-3 py-3 sm:px-5">
                            <span class="chip" :class="chipTone[row.movement_type]">
                                {{ row.movement_type_label }}
                            </span>
                        </td>

                        <td class="px-3 py-3 sm:px-5">
                            <p class="text-small font-medium">
                                {{ row.product?.name ?? 'Produk dihapus' }}
                            </p>
                            <p class="text-caption text-content-muted">
                                {{ row.product?.sku ?? '-' }}
                            </p>
                        </td>

                        <td
                            class="px-3 py-3 text-right font-mono text-small font-medium sm:px-5"
                            :class="row.quantity >= 0 ? 'text-emerald-600' : 'text-danger'"
                        >
                            {{ row.quantity > 0 ? '+' : '' }}{{ row.quantity }}
                        </td>

                        <td class="px-3 py-3 text-small sm:px-5">
                            {{ row.user?.name ?? 'sistem' }}
                        </td>

                        <td class="px-3 py-3 sm:px-5">
                            <p class="text-caption text-content-muted">
                                {{ row.reference_code ?? row.system_code ?? '-' }}
                            </p>

                            <!--
                                Pemasok hanya relevan untuk barang masuk. Datanya sudah
                                dikirim controller; tanpa sel ini, pengguna tidak punya
                                cara melihat barang datang dari mana.
                            -->
                            <p v-if="row.supplier" class="text-caption text-content-secondary">
                                {{ row.supplier.name }}
                            </p>

                            <!--
                                Satu faktur bisa menutup banyak SKU. Tanpa penanda ini,
                                nomor dokumen yang sama muncul N kali dan terlihat seperti
                                duplikasi data -- padahal itu memang satu dokumen.
                            -->
                            <p
                                v-else-if="row.sibling_count > 1"
                                class="text-caption text-content-muted"
                            >
                                {{ row.sibling_count }} baris dalam satu faktur
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="movements.last_page > 1" class="border-t border-hairline px-6 py-4">
            <nav class="flex items-center justify-between text-small">
                <span class="text-content-muted">
                    Halaman {{ movements.current_page }} dari {{ movements.last_page }}
                    ({{ movements.total }} baris)
                </span>

                <div class="flex gap-2">
                    <a
                        v-if="movements.prev_page_url"
                        :href="movements.prev_page_url"
                        class="btn-secondary btn-sm"
                    >
                        Sebelumnya
                    </a>
                    <a
                        v-if="movements.next_page_url"
                        :href="movements.next_page_url"
                        class="btn-secondary btn-sm"
                    >
                        Berikutnya
                    </a>
                </div>
            </nav>
        </div>
    </div>
</template>