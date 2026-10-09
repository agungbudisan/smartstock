<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';

const props = defineProps({
    recommendations: { type: Array, required: true },
    categories: { type: Array, required: true },
    filters: { type: Object, required: true },
});

const categoryId = ref(props.filters.category_id ?? '');
const onlyNeeding = ref(props.filters.only_needing_reorder ?? true);

function apply() {
    router.get(
        '/analytics/reorder',
        {
            category_id: categoryId.value || null,
            only_needing_reorder: onlyNeeding.value ? 1 : null,
        },
        { preserveState: true, replace: true }
    );
}

watch([categoryId, onlyNeeding], apply);

const urgentCount = ref(0);

// Hitung ulang dari props setiap kali data berubah.
watch(
    () => props.recommendations,
    (rows) => {
        urgentCount.value = rows.filter((row) => row.isUrgent).length;
    },
    { immediate: true }
);
</script>

<template>
    <Head title="Reorder Point" />

    <AppLayout title="Pemantauan Reorder Point">
        <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="overline">Prioritas Pemesanan</p>
                <h1 class="mt-1 text-section">Daftar Reorder</h1>
                <p class="mt-1 text-body text-content-secondary">
                    Diurutkan dari yang paling mendesak: stok habis, lalu stok yang habis sebelum barang tiba.
                </p>
            </div>

            <a href="/analytics/reorder.csv" class="btn-secondary">Unduh CSV</a>
        </div>

        <div class="mb-6 flex flex-wrap items-center gap-3">
            <select v-model="categoryId" class="input w-auto">
                <option value="">Semua kategori</option>
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>

            <label class="flex cursor-pointer items-center gap-2 text-small">
                <input
                    v-model="onlyNeeding"
                    type="checkbox"
                    class="rounded-full border-gray-300 text-primary focus:ring-primary"
                />
                Hanya produk di bawah batas aman
            </label>

            <span v-if="urgentCount" class="chip-danger">{{ urgentCount }} mendesak</span>
        </div>

        <section class="card overflow-hidden">
            <p
                v-if="!recommendations.length"
                class="px-6 py-12 text-center text-small text-content-muted"
            >
                Tidak ada produk yang perlu dipesan ulang saat ini.
            </p>

            <!--
                Tabel melebar digulir horizontal, bukan dipotong. Tanpa ini,
                `.card` yang `overflow-hidden` memotong kolom yang tidak muat
                dan datanya hilang tanpa jejak.

                `v-else` menempel di `<div>` ini, bukan di `<table>`: Vue
                mensyaratkan `v-if` dan `v-else` bersebelahan tanpa elemen lain
                di antaranya, dan yang menegakkannya adalah `npm run build`.
            -->
            <div v-else class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-surface-muted">
                        <tr class="text-left">
                            <th class="px-3 py-3 sm:px-5 text-overline">Produk</th>
                            <th class="px-3 py-3 sm:px-5 text-overline text-right">Stok</th>
                            <th class="px-3 py-3 sm:px-5 text-overline text-right">Batas Aman</th>
                            <th class="px-3 py-3 sm:px-5 text-overline text-right">Lead Time</th>
                            <th class="px-3 py-3 sm:px-5 text-overline text-right">Demand/Hari</th>
                            <th class="px-3 py-3 sm:px-5 text-overline text-right">Stok Tahan</th>
                            <th class="px-3 py-3 sm:px-5 text-overline text-right">Saran Pesan</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="item in recommendations" :key="item.productId" class="table-row">
                            <td class="px-3 py-4 sm:px-5">
                                <p class="text-small font-medium">{{ item.name }}</p>
                                <p class="font-mono text-caption text-content-muted">
                                    {{ item.sku }} · {{ item.categoryName ?? 'tanpa kategori' }}
                                </p>
                            </td>

                            <td class="px-3 py-4 text-right font-display text-subhead sm:px-5">
                                {{ item.currentStock }}
                            </td>

                            <td class="px-3 py-4 text-right text-small text-content-secondary sm:px-5">
                                {{ item.reorderPoint }}
                            </td>

                            <td class="px-3 py-4 text-right text-small sm:px-5">
                                {{ item.leadTimeDays }}h
                            </td>

                            <td class="px-3 py-4 text-right text-small sm:px-5">
                                {{ item.averageDailyDemand }}
                            </td>

                            <td class="px-3 py-4 text-right text-small sm:px-5">
                                <span v-if="item.daysOfCover === null" class="text-content-muted">-</span>
                                <span v-else :class="item.isUrgent ? 'text-danger font-medium' : ''">
                                    {{ item.daysOfCover }} hari
                                </span>
                            </td>

                            <td class="px-3 py-4 text-right sm:px-5">
                                <span :class="item.isUrgent ? 'chip-danger' : 'chip-warning'">
                                    {{ item.suggestedOrderQty }} pcs
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <p class="mt-4 text-caption text-content-muted">
            Demand harian = total outbound / jumlah hari dalam jendela 30 hari terakhir. Hari tanpa barang keluar
            dihitung 0, bukan diabaikan.
        </p>
    </AppLayout>
</template>