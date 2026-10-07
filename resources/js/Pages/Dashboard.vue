<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    stats: { type: Object, required: true },
    role: { type: String, required: true },
});
</script>

<template>
    <Head title="Beranda" />

    <AppLayout title="Beranda">
        <div class="mb-8">
            <p class="overline">Admin Gudang</p>
            <h1 class="mt-1 text-section">Beranda Operasional</h1>
            <p class="mt-1 text-body text-content-secondary">
                Fokus hari ini: catat pergerakan stok dan perhatikan produk yang menipis.
            </p>
        </div>

        <section class="mb-8 grid gap-4 sm:grid-cols-3">
            <div class="card card-hover p-5">
                <p class="overline">Perlu Reorder</p>
                <p class="mt-2 font-display text-headline text-amber-600">{{ stats.low_stock }}</p>
            </div>
            <div class="card card-hover p-5">
                <p class="overline">Stok Habis</p>
                <p class="mt-2 font-display text-headline text-danger">{{ stats.out_of_stock }}</p>
            </div>
            <div class="card card-hover p-5">
                <p class="overline">Pergerakan Hari Ini</p>
                <p class="mt-2 font-display text-headline text-content">{{ stats.movements_today }}</p>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2">
            <Link href="/inventory/movements" class="card card-hover p-6">
                <h2 class="text-subhead">Catat Inbound / Outbound</h2>
                <p class="mt-1 text-small text-content-secondary">
                    Form operasional dengan validasi stok dan pratinjau sisa stok sebelum disimpan.
                </p>
            </Link>

            <Link href="/products" class="card card-hover p-6">
                <h2 class="text-subhead">Kelola Katalog</h2>
                <p class="mt-1 text-small text-content-secondary">
                    Produk, kategori, dan pemasok beserta pemetaan harga beli serta lead time.
                </p>
            </Link>
        </section>
    </AppLayout>
</template>