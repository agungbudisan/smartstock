<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import BarChart from '@/Components/Charts/BarChart.vue';
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    report: { type: Object, required: true },
    coverage: { type: Number, default: null },
    rows: { type: Array, required: true },
    trend: { type: Array, required: true },
    months: { type: Array, required: true },
    filters: { type: Object, required: true },
});

const month = ref(props.filters.month);

watch(month, (value) => {
    router.get(
        '/analytics/accuracy',
        { month: value },
        { preserveState: true, replace: true }
    );
});

const pct = (value, digits = 2) =>
    value === null || value === undefined ? '—' : `${Number(value).toFixed(digits)}%`;

const num = (value) =>
    value === null || value === undefined ? '—' : Number(value).toLocaleString('id-ID');

/**
 * WAPE jadi metrik utama: aman terhadap aktual nol, dan berbobot nilai
 * barang sehingga produk besar tidak tenggelam oleh produk kecil.
 */
const wapeTone = computed(() => {
    const wape = props.report.wape;

    if (wape === null) return 'neutral';
    if (wape <= 25) return 'success';
    if (wape <= 50) return 'warning';

    return 'danger';
});

const toneClasses = {
    success: 'text-emerald-600',
    warning: 'text-amber-600',
    danger: 'text-danger',
    neutral: 'text-content-muted',
};

/** Bias positif = sering over-predict; negatif = sering under-predict. */
const biasTone = computed(() => {
    const bias = props.report.bias;

    if (bias === null) return 'neutral';
    if (Math.abs(bias) < 1) return 'success';

    return bias > 0 ? 'warning' : 'danger';
});

const metrics = computed(() => [
    {
        label: 'WAPE',
        hint: 'Rata-rata err tertimbang. Metrik utama.',
        value: pct(props.report.wape),
        tone: wapeTone.value,
    },
    {
        label: 'MAE',
        hint: 'Rata-rata kesalahan absolut, dalam pcs.',
        value: `${num(props.report.mae)} pcs`,
        tone: 'neutral',
    },
    {
        label: 'MAPE',
        hint: `Hanya ${props.report.compared_count} dari ${props.report.prediction_count} produk yang punya penjualan nyata.`,
        value: pct(props.report.mape),
        tone: 'neutral',
    },
    {
        label: 'RMSE',
        hint: 'Memberi penalti lebih berat pada kesalahan besar.',
        value: `${num(props.report.rmse)} pcs`,
        tone: 'neutral',
    },
    {
        label: 'Bias',
        hint:
            props.report.bias > 0
                ? 'Cenderung over-predict.'
                : props.report.bias < 0
                  ? 'Cenderung under-predict.'
                  : 'Tidak ada kecenderungan.',
        value: `${props.report.bias > 0 ? '+' : ''}${num(props.report.bias)} pcs`,
        tone: biasTone.value,
    },
]);

const trendMax = computed(() => Math.max(1, ...props.trend.map((t) => t.wape ?? 0)));

const search = ref('');
const onlyOffenders = ref(false);

const visibleRows = computed(() => {
    const keyword = search.value.trim().toLowerCase();

    return props.rows.filter((row) => {
        if (onlyOffenders.value && row.error === 0) return false;
        if (!keyword) return true;

        return (
            row.sku?.toLowerCase().includes(keyword) ||
            row.name?.toLowerCase().includes(keyword)
        );
    });
});

const sortedRows = computed(() =>
    [...visibleRows.value].sort((a, b) => (b.error_pct ?? 1e9) - (a.error_pct ?? 1e9))
);

const directionChip = { over: 'chip-warning', under: 'chip-danger', exact: 'chip-success' };
const directionLabel = { over: 'Over', under: 'Under', exact: 'Tepat' };
</script>

<template>
    <AppLayout title="Evaluasi Akurasi">
        <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="overline">Business Intelligence</p>
                <h1 class="mt-1 text-section">Evaluasi Akurasi Prediksi</h1>
                <p class="mt-1 max-w-2xl text-body text-content-secondary">
                    Mengukur seberapa dekat prediksi algoritma dengan permintaan yang benar-benar
                    terjadi. Hanya bulan yang sudah lewat penuh yang dinilai.
                </p>
            </div>

            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="label" for="month">Bulan target</label>
                    <select id="month" v-model="month" class="input">
                        <option v-for="m in months" :key="m.value" :value="m.value">
                            {{ m.label }}{{ m.is_evaluable ? '' : ' (belum selesai)' }}
                        </option>
                    </select>
                </div>

                <Link :href="`/analytics`" class="btn-ghost btn-sm">Dasbor</Link>
                <a
                    :href="`/analytics/accuracy.csv?month=${report.target_month}`"
                    class="btn-primary btn-sm"
                >
                    Unduh CSV
                </a>
            </div>
        </div>

        <div
            v-if="!report.is_evaluable"
            class="mb-6 rounded-card border border-amber-200 bg-amber-50 px-5 py-4 text-small text-amber-800"
        >
            <p class="font-medium">Bulan ini belum selesai, jadi angkanya belum bisa dipakai.</p>
            <p class="mt-1">
                Permintaan aktual baru terkumpul sebagian hari. Nilainya di bawah ditampilkan apa
                adanya untuk keperluan debugging, bukan sebagai penilaian algoritma.
            </p>
        </div>

        <section class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div v-for="metric in metrics" :key="metric.label" class="card p-5">
                <p class="overline">{{ metric.label }}</p>
                <p class="mt-2 font-display text-headline" :class="toneClasses[metric.tone]">
                    {{ metric.value }}
                </p>
                <p class="mt-1 text-caption text-content-muted">{{ metric.hint }}</p>
            </div>
        </section>

        <section class="mb-8 grid gap-4 sm:grid-cols-3">
            <div class="card p-5">
                <p class="overline">Total Prediksi</p>
                <p class="mt-1 font-display text-subhead">{{ num(report.total_predicted) }}</p>
            </div>

            <div class="card p-5">
                <p class="overline">Total Aktual</p>
                <p class="mt-1 font-display text-subhead">{{ num(report.total_actual) }}</p>
            </div>

            <div class="card p-5">
                <p class="overline">Kelengkapan Data</p>
                <p class="mt-1 font-display text-subhead">
                    {{ coverage === null ? '—' : `${coverage}%` }}
                </p>
                <p class="mt-1 text-caption text-content-muted">
                    {{ report.compared_count }} dari {{ report.prediction_count }} produk punya
                    penjualan nyata. {{ report.zero_actual_count }} produk aktualnya nol.
                </p>
            </div>
        </section>

        <section class="card mb-8 p-6">
            <h2 class="text-subhead">Tren WAPE per Bulan</h2>
            <p class="mb-6 mt-1 text-small text-content-secondary">
                Makin rendah makin akurat. Bulan yang belum selesai ditandai di bawah grafik.
            </p>

            <p
                v-if="!trend.length"
                class="rounded-panel bg-surface-muted px-4 py-10 text-center text-small text-content-muted"
            >
                Belum ada prediksi yang bisa dinilai.
            </p>

            <BarChart
                v-else
                :data="
                    trend.map((t) => ({
                        label: t.label,
                        value: t.wape ?? 0,
                        meta: t.compared_count,
                        note: t.is_evaluable ? '' : 'belum selesai',
                    }))
                "
                :max="trendMax"
                value-suffix="%"
                meta-suffix=" produk"
                show-note
            />
        </section>

        <section class="card">
            <div
                class="flex flex-wrap items-end justify-between gap-4 border-b border-hairline px-6 py-4"
            >
                <div>
                    <h2 class="text-subhead">Rincian per Produk</h2>
                    <p class="mt-0.5 text-small text-content-secondary">
                        Error persen tidak terdefinisi bila produk tidak terjual sama sekali.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <div>
                        <label class="label" for="cari">Cari</label>
                        <input
                            id="cari"
                            v-model="search"
                            type="search"
                            class="input"
                            placeholder="SKU atau nama"
                        />
                    </div>

                    <label class="mb-1.5 flex items-center gap-2 text-small text-content-secondary">
                        <input v-model="onlyOffenders" type="checkbox" />
                        Hanya yang meleset
                    </label>
                </div>
            </div>

            <p
                v-if="!sortedRows.length"
                class="px-6 py-10 text-center text-small text-content-muted"
            >
                Tidak ada baris yang cocok dengan filter.
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
                            <th class="px-3 py-3 sm:px-6 text-overline">Aktual</th>
                            <th class="px-3 py-3 sm:px-6 text-overline">Selisih</th>
                            <th class="px-3 py-3 sm:px-6 text-overline">Error</th>
                            <th class="px-3 py-3 sm:px-6 text-overline">Arah</th>
                            <th class="px-3 py-3 sm:px-6 text-overline">Confidence</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="row in sortedRows" :key="row.product_id" class="table-row">
                            <td class="px-3 py-4 sm:px-6">
                                <p class="text-small font-medium">{{ row.name }}</p>
                                <p class="text-caption text-content-muted">{{ row.sku }}</p>
                            </td>

                            <td class="px-3 py-4 font-mono text-small sm:px-6">
                                {{ num(row.predicted_qty) }}
                            </td>

                            <td class="px-3 py-4 font-mono text-small sm:px-6">
                                {{ num(row.actual_qty) }}
                            </td>

                            <td
                                class="px-3 py-4 font-mono text-small sm:px-6"
                                :class="
                                    row.error > 0
                                        ? 'text-amber-600'
                                        : row.error < 0
                                          ? 'text-danger'
                                          : ''
                                "
                            >
                                {{ row.error > 0 ? '+' : '' }}{{ num(row.error) }}
                            </td>

                            <td class="px-3 py-4 font-mono text-small sm:px-6">
                                {{ row.error_pct === null ? '—' : pct(row.error_pct, 1) }}
                            </td>

                            <td class="px-3 py-4 sm:px-6">
                                <span :class="directionChip[row.direction]">
                                    {{ directionLabel[row.direction] }}
                                </span>
                            </td>

                            <td class="px-3 py-4 text-small text-content-secondary sm:px-6">
                                {{ row.confidence_score }}%
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>