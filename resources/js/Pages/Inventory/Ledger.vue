<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import LedgerTable from '@/Components/Inventory/LedgerTable.vue';
import { Head, router } from '@inertiajs/vue3';

/**
 * Buku Besar Stok sebagai halaman mandiri, untuk peran yang boleh MEMBACA
 * ledger tetapi tidak boleh MENCATAT pergerakan.
 *
 * Kenapa halaman terpisah, bukan form yang disembunyikan sesuai peran:
 *
 *  - URL jadi jujur. `/inventory/movements` adalah form entri dan hanya bisa
 *    dibuka Admin Gudang; `/inventory/ledger` adalah buku besar dan terbuka
 *    untuk keduanya. Batas wewenang terbaca langsung di tabel route, bukan
 *    tersembunyi di balik `v-if` di dalam template.
 *  - Halaman "Entri Pergerakan Stok" milik Admin Gudang menampilkan form yang
 *    tidak bisa disimpan oleh siapa pun selain Admin. Itu jebakan: operator
 *    mengisi dengan benar lalu menekan Simpan dan mendapat 403 tanpa penjelasan.
 *
 * Halaman ini sengaja tidak punya apa pun yang bisa ditulis. Kalau suatu saat
 * Manager butuh entri, itu perubahan kebijakan -- bukan kontrol yang lupa
 * disembunyikan.
 */
const props = defineProps({
    movements: { type: Object, required: true },
    filters: { type: Object, required: true },
    products: { type: Array, required: true },
    movementTypes: { type: Array, required: true },
});

function terapkanFilter(query) {
    router.get(
        '/inventory/ledger',
        query,
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <Head title="Buku Besar Stok" />

    <AppLayout title="Buku Besar Stok">
        <div class="mb-8">
            <p class="overline">Jejak Audit</p>
            <h1 class="mt-1 text-section">Buku Besar Stok</h1>
            <p class="mt-1 text-body text-content-secondary">
                Setiap perubahan stok tercatat di sini dengan faktur, pencatat, dan asal-usulnya.
                Halaman ini hanya dibaca; pencatatan baru dilakukan oleh Admin Gudang.
            </p>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="card overflow-hidden lg:col-span-3">
                <LedgerTable
                    :movements="movements"
                    :filters="filters"
                    :products="products"
                    :movement-types="movementTypes"
                    @change="terapkanFilter"
                />
            </section>
        </div>
    </AppLayout>
</template>