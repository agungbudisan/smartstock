<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import BarChart from '@/Components/Charts/BarChart.vue';
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';

defineProps({
    kpi: { type: Object, required: true },
    trend: { type: Array, required: true },
    predictions: { type: Array, required: true },
    reorderTop: { type: Array, required: true },
    availableMonths: { type: Array, required: true },
    categories: { type: Array, required: true },
    filters: { type: Object, required: true },
});

const page = usePage();

const canReorder = computed(() => page.props.auth.user?.role === 'manager_bisnis');
const month = ref(page.props.filters.month);
const categoryId = ref(page.props.filters.category_id ?? '');

function applyFilters() {
    router.get(
        '/analytics',
        {
            month: month.value,
            category_id: categoryId.value === '' ? null : categoryId.value,
        },
        { preserveState: true, replace: true }
    );
}

function resetFilters() {
    month.value = page.props.filters.month;
    categoryId.value = '';
    applyFilters();
}

// Skala grafik: tinggi batang proporsional terhadap nilai tertinggi.
const chartMax = computed(() => Math.max(1, ...page.props.trend.map((t) => t.total_predicted)));

/**
 * Keterangan tiap batang: dari mana angka itu berasal.
 *
 * Ketiganya ditampilkan berdampingan sebagai "Total kuantitas prediksi", tapi
 * maknanya berbeda -- dan tanpa penanda, deret yang lompat bulan terlihat
 * seperti data rusak, bukan seperti tiga jenis angka yang memang berbeda.
 */
const STATUS = {
    riwayat: ' riwayat (backtest)',
    berjalan: ' berjalan',
    proyeksi: ' proyeksi',
};

const chartData = computed(() =>
    page.props.trend.map((t) => ({
        label: t.label + (STATUS[t.status] ?? ''),
        value: t.total_predicted,
        meta: t.avg_confidence,
    }))
);

/*
 * Dropdown bulan. Dulu `:key="m"` memakai nilai bulan, yang berulang ketika
 * server mengirim bulan yang sama berkali-kali -- Vue lalu memperingatkan
 * tentang key duplikat. Indeks dipakai sebagai kunci karena urutan opsi
 * tidak pernah berubah tanpa halaman dirender ulang.
 */
const bulanOptions = computed(() =>
    page.props.availableMonths.map((value, index) => ({ value, index }))
);

const cards = computed(() => [
    { label: 'Total Produk', value: page.props.kpi.total_products, tone: 'default' },
    { label: 'Perlu Reorder', value: page.props.kpi.needs_reorder, tone: 'warning' },
    { label: 'Stok Habis', value: page.props.kpi.out_of_stock, tone: 'danger' },
    {
        label: 'Prediksi Bulan Dipilih',
        value: page.props.kpi.predicted_total,
        tone: 'primary',
        hint: `${page.props.kpi.predicted_products} produk punya prediksi`,
    },
    {
        label: 'Rata-rata Confidence',
        value: `${page.props.kpi.average_confidence}%`,
        tone: page.props.kpi.average_confidence >= 70 ? 'success' : 'warning',
    },
]);

const toneClasses = {
    default: 'text-content',
    primary: 'text-primary',
    warning: 'text-amber-600',
    danger: 'text-danger',
    success: 'text-emerald-600',
};

const confidenceTone = (score) =>
    score >= 70 ? 'chip-success' : score >= 40 ? 'chip-warning' : 'chip-danger';

watch([month, categoryId], applyFilters);
</script>

<template>
    <AppLayout title="Dasbor Manajer">
        <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="overline">Business Intelligence</p>
                <h1 class="mt-1 text-section">Dasbor Permintaan</h1>
                <p class="mt-1 text-body text-content-secondary">
                    Prediksi 30 hari ke depan dari agregasi harian, dihitung tiap tengah malam.
                </p>
            </div>

            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="label" for="month">Bulan target</label>
                    <select id="month" v-model="month" class="input">
                        <option v-for="b in bulanOptions" :key="b.index" :value="b.value">
                            {{
                                new Date(b.value).toLocaleDateString('id-ID', {
                                    month: 'long',
                                    year: 'numeric',
                                })
                            }}
                        </option>
                    </select>
                </div>

                <div>
                    <label class="label" for="category">Kategori</label>
                    <select id="category" v-model="categoryId" class="input">
                        <option value="">Semua kategori</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>

                <button type="button" class="btn-ghost btn-sm" @click="resetFilters">Reset</button>
                <a :href="`/analytics/reorder.csv`" class="btn-secondary btn-sm">Unduh CSV</a>
            </div>
        </div>

        <section class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div v-for="card in cards" :key="card.label" class="card card-hover p-5">
                <p class="overline">{{ card.label }}</p>
                <p class="mt-2 font-display text-headline" :class="toneClasses[card.tone]">
                    {{
                        typeof card.value === 'number'
                            ? card.value.toLocaleString('id-ID')
                            : card.value
                    }}
                </p>
                <p v-if="card.hint" class="mt-1 text-caption text-content-muted">{{ card.hint }}</p>
            </div>
        </section>

        <section class="mb-8 grid gap-6 lg:grid-cols-3">
            <div class="card p-6 lg:col-span-2">
                <h2 class="text-subhead">Tren Permintaan Bulanan</h2>
                <p class="mb-6 mt-1 text-small text-content-secondary">
                    Total kuantitas permintaan per bulan. Batang bertanda
                    <span class="font-medium">riwayat</span> dihitung ulang dari data bulan itu,
                    <span class="font-medium">berjalan</span> adalah bulan sekarang, dan
                    <span class="font-medium">proyeksi</span> belum punya data.
                </p>

                <div
                    v-if="!trend.length"
                    class="rounded-panel bg-surface-muted px-4 py-10 text-center text-small text-content-muted"
                >
                    Belum ada hasil prediksi. Job <code>CalculateMonthlyPredictionJob</code> berjalan
                    setiap 00:00.
                </div>

                <BarChart v-else :data="chartData" :max="chartMax" />
            </div>

            <div class="card p-6">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-subhead">Prioritas Reorder</h2>
                    <Link
                        v-if="canReorder"
                        href="/analytics/reorder"
                        class="text-small font-medium text-primary"
                    >
                        Semua
                    </Link>
                </div>

                <p v-if="!reorderTop.length" class="text-small text-content-muted">
                    Tidak ada produk di bawah batas aman. Bagus.
                </p>

                <ul v-else class="divide-y divide-hairline">
                    <li
                        v-for="item in reorderTop"
                        :key="item.productId"
                        class="flex items-center justify-between gap-3 py-3"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-small font-medium">{{ item.name }}</p>
                            <p class="text-caption text-content-muted">
                                {{ item.sku }} · stok {{ item.currentStock }} · lead time
                                {{ item.leadTimeDays }}h
                            </p>
                        </div>

                        <span :class="item.isUrgent ? 'chip-danger' : 'chip-warning'">
                            {{ item.suggestedOrderQty }} pcs
                        </span>
                    </li>
                </ul>
            </div>
        </section>

        <section class="card">
            <div class="border-b border-hairline px-6 py-4">
                <h2 class="text-subhead">Rincian Prediksi</h2>
                <p class="mt-0.5 text-small text-content-secondary">
                    Confidence berasal dari R² regresi linear. Di bawah 50% berarti riwayat kurang
                    meyakinkan.
                </p>
            </div>

            <p
                v-if="!predictions.length"
                class="px-6 py-10 text-center text-small text-content-muted"
            >
                Belum ada prediksi untuk filter ini.
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
                            <th class="px-3 py-3 sm:px-6 text-overline">Produk</th>
                            <th class="px-3 py-3 sm:px-6 text-overline">Prediksi</th>
                            <th class="px-3 py-3 sm:px-6 text-overline">Stok Sekarang</th>
                            <th class="px-3 py-3 sm:px-6 text-overline">Confidence</th>
                            <th class="px-3 py-3 sm:px-6 text-overline">Dihitung</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="row in predictions" :key="row.product_id" class="table-row">
                            <td class="px-3 py-4 sm:px-6">
                                <p class="text-small font-medium">{{ row.name }}</p>
                                <p class="text-caption text-content-muted">{{ row.sku }}</p>
                            </td>

                            <td class="px-3 py-4 font-display text-subhead sm:px-6">
                                {{ row.predicted_qty.toLocaleString('id-ID') }}
                            </td>

                            <td class="px-3 py-4 text-small sm:px-6">
                                {{ row.current_stock?.toLocaleString('id-ID') }}
                            </td>

                            <td class="px-3 py-4 sm:px-6">
                                <span :class="confidenceTone(row.confidence_score)">
                                    {{ row.confidence_score }}%
                                </span>
                            </td>

                            <td class="px-3 py-4 text-caption text-content-muted sm:px-6">
                                {{
                                    row.calculated_at
                                        ? new Date(row.calculated_at).toLocaleString('id-ID')
                                        : '-'
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>