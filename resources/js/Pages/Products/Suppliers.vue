<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    product: { type: Object, required: true },
    linked: { type: Array, required: true },
    available: { type: Array, required: true },
    defaults: { type: Object, required: true },
});

/**
 * Setiap baris menyimpan `originalId` supaya UI bisa:
 *  - menandai baris baru (belum pernah terpasang),
 *  - memberi peringatan saat baris lama dilepas,
 *  - mencegah pemasok yang sama dipilih dua kali.
 */
const initialRows = () =>
    props.linked.map((row) => ({
        supplier_id: row.id,
        original_id: row.id,
        last_purchase_price: row.last_purchase_price ?? '',
        lead_time_days: row.lead_time_days ?? '',
    }));

const form = useForm({ suppliers: initialRows() });

const nameById = computed(() => {
    const map = {};

    for (const row of props.linked) map[row.id] = row.name;
    for (const row of props.available) map[row.id] = row.name;

    return map;
});

/** ID yang sudah dipakai baris lain — untuk menonaktifkan opsi di select. */
const takenIds = computed(() => new Set(form.suppliers.map((row) => row.supplier_id)));

/** Baris yang berasal dari relasi tersimpan dan masih utuh. */
const detached = computed(() =>
    form.suppliers.filter((row) => row.original_id && row.supplier_id !== row.original_id)
);

const duplicateIds = computed(() => {
    const counts = {};

    for (const row of form.suppliers) {
        if (!row.supplier_id) continue;

        counts[row.supplier_id] = (counts[row.supplier_id] ?? 0) + 1;
    }

    return Object.keys(counts).filter((id) => counts[id] > 1);
});

/** Lead time tercepat dari baris yang sedang diisi — sama dengan yang dipakai ReorderService. */
const fastestLeadTime = computed(() => {
    let fastest = null;

    for (const row of form.suppliers) {
        if (row.lead_time_days === '' || row.lead_time_days === null) continue;

        const days = Number(row.lead_time_days);

        if (!Number.isFinite(days) || days < 0) continue;
        if (fastest === null || days < fastest) fastest = days;
    }

    return fastest;
});

const missingLeadTime = computed(
    () =>
        form.suppliers.filter(
            (row) => row.supplier_id && (row.lead_time_days === '' || row.lead_time_days === null)
        ).length
);

const hasUnsaved = computed(() => form.isDirty);
const canSubmit = computed(() => duplicateIds.value.length === 0);

function addRow() {
    form.suppliers.push({
        supplier_id: '',
        original_id: null,
        last_purchase_price: '',
        lead_time_days: props.defaults.default_lead_time_days,
    });
}

function removeRow(index) {
    form.suppliers.splice(index, 1);
}

function reset() {
    form.defaults({ suppliers: initialRows() });
    form.reset();
}

function submit() {
    form.put(`/products/${props.product.id}/suppliers`, {
        preserveScroll: true,
        onSuccess: () => form.defaults({ suppliers: form.suppliers }),
    });
}
</script>

<template>
    <Head :title="`Pemasok ${product.sku}`" />

    <AppLayout title="Pemasok Produk">
        <div class="mb-6">
            <Link href="/products" class="text-small text-content-secondary hover:text-primary">
                &larr; Kembali ke katalog
            </Link>

            <p class="overline mt-4">Pemetaan Harga &amp; Lead Time</p>
            <h1 class="mt-1 text-section">{{ product.name }}</h1>
            <p class="mt-1 font-mono text-small text-content-muted">
                {{ product.sku }} &middot; stok {{ product.current_stock }} &middot; batas aman
                {{ product.reorder_point }}
            </p>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="card p-6 lg:col-span-2">
                <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-subhead">Pemasok Terpasang</h2>
                        <p class="mt-0.5 text-small text-content-secondary">
                            Satu produk dapat disuplai banyak pemasok. Lead time tercepat dipakai
                            untuk menghitung kapan stok habis.
                        </p>
                    </div>

                    <button type="button" class="btn-secondary btn-sm shrink-0" @click="addRow">
                        Tambah Baris
                    </button>
                </div>

                <p
                    v-if="!form.suppliers.length"
                    class="rounded-panel bg-surface-muted px-4 py-8 text-center text-small text-content-muted"
                >
                    Belum ada pemasok. Klik &quot;Tambah Baris&quot; untuk memetakan.
                </p>

                <!--
                    Tabel melebar digulir horizontal, bukan dipotong. Tanpa ini,
                    `.card` yang `overflow-hidden` memotong kolom yang tidak muat
                    dan datanya hilang tanpa jejak.

                    `v-else` menempel di `<div>` ini, bukan di `<table>`: Vue
                    mensyaratkan `v-if` dan `v-else` bersebelahan tanpa elemen
                    lain di antaranya, dan `npm run build` yang menegakkannya.
                -->
                <div v-else class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-surface-muted">
                            <tr class="text-left">
                                <th class="px-2.5 py-2.5 sm:px-4 text-overline">Pemasok</th>
                                <th class="px-2.5 py-2.5 sm:px-4 text-overline">
                                    Harga Beli Terakhir
                                </th>
                                <th class="px-2.5 py-2.5 sm:px-4 text-overline">Lead Time</th>
                                <th class="w-12 px-2.5 py-2.5 sm:px-4"></th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="(row, index) in form.suppliers"
                                :key="index"
                                class="table-row"
                            >
                                <td class="px-2.5 py-3 sm:px-4">
                                    <select
                                        v-model="row.supplier_id"
                                        class="input"
                                        :class="{ 'input-error': !row.supplier_id }"
                                        :aria-label="`Pemasok baris ${index + 1}`"
                                    >
                                        <option value="">-- pilih pemasok --</option>
                                        <option
                                            v-for="option in available"
                                            :key="option.id"
                                            :value="option.id"
                                            :disabled="
                                                takenIds.has(option.id) &&
                                                option.id !== row.supplier_id
                                            "
                                        >
                                            {{ option.name }}
                                        </option>
                                        <option
                                            v-if="
                                                row.supplier_id &&
                                                !available.some((a) => a.id === row.supplier_id)
                                            "
                                            :value="row.supplier_id"
                                        >
                                            {{ nameById[row.supplier_id] }} (terpasang)
                                        </option>
                                    </select>

                                    <p
                                        v-if="!row.supplier_id"
                                        class="mt-1 text-caption text-content-muted"
                                    >
                                        Baris ini belum dipilih, jadi akan dilewati.
                                    </p>
                                    <p
                                        v-else-if="
                                            row.original_id &&
                                            row.original_id !== row.supplier_id
                                        "
                                        class="mt-1 text-caption text-amber-600"
                                    >
                                        Mengganti pilihan akan melepas
                                        {{ nameById[row.original_id] }} dari produk ini.
                                    </p>
                                </td>

                                <td class="px-2.5 py-3 sm:px-4">
                                    <input
                                        v-model="row.last_purchase_price"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        class="input"
                                        placeholder="0"
                                        :aria-label="`Harga beli baris ${index + 1}`"
                                    />
                                </td>

                                <td class="px-2.5 py-3 sm:px-4">
                                    <div class="flex items-center gap-2">
                                        <input
                                            v-model="row.lead_time_days"
                                            type="number"
                                            min="0"
                                            max="365"
                                            class="input"
                                            placeholder="hari"
                                            :aria-label="`Lead time baris ${index + 1}`"
                                        />
                                        <span
                                            v-if="
                                                row.lead_time_days !== '' &&
                                                Number(row.lead_time_days) === fastestLeadTime
                                            "
                                            class="chip-success shrink-0"
                                        >
                                            tercepat
                                        </span>
                                    </div>
                                </td>

                                <td class="px-2.5 py-3 text-right sm:px-4">
                                    <button
                                        type="button"
                                        class="btn-ghost btn-sm text-danger"
                                        :aria-label="`Hapus baris ${index + 1}`"
                                        @click="removeRow(index)"
                                    >
                                        Hapus
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="form.errors.suppliers" class="mt-3 text-caption text-danger">
                    {{ form.errors.suppliers }}
                </div>

                <div
                    v-for="(messages, field) in form.errors"
                    :key="field"
                    v-show="field !== 'suppliers'"
                    class="mt-1 text-caption text-danger"
                >
                    {{ messages }}
                </div>

                <p v-if="duplicateIds.length" class="mt-3 text-caption text-danger">
                    Pemasok yang sama tercatat lebih dari sekali. Ubah salah satu baris sebelum
                    menyimpan.
                </p>

                <p v-else-if="detached.length" class="mt-3 text-caption text-amber-600">
                    {{ detached.length }} pemasok akan dilepas dari produk ini bila Anda menyimpan.
                </p>

                <div class="mt-6 flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        class="btn-primary"
                        :disabled="form.processing || !canSubmit"
                        @click="submit"
                    >
                        {{ form.processing ? 'Menyimpan...' : 'Simpan Pemetaan' }}
                    </button>

                    <button
                        type="button"
                        class="btn-ghost"
                        :disabled="!hasUnsaved || form.processing"
                        @click="reset"
                    >
                        Batalkan perubahan
                    </button>

                    <span v-if="hasUnsaved" class="text-caption text-amber-600">
                        Ada perubahan yang belum disimpan.
                    </span>
                </div>
            </section>

            <aside class="card h-fit p-6">
                <h2 class="text-subhead">Cara Hitung</h2>

                <dl class="mt-4 space-y-3 text-small">
                    <div>
                        <dt class="text-content-secondary">Lead time tercepat</dt>
                        <dd class="font-display text-subhead">
                            {{ fastestLeadTime === null ? '—' : fastestLeadTime + ' hari' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-content-secondary">Jumlah pemasok</dt>
                        <dd class="font-display text-subhead">
                            {{ form.suppliers.filter((r) => r.supplier_id).length }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-content-secondary">Pemasok tanpa lead time</dt>
                        <dd class="font-display text-subhead">{{ missingLeadTime }}</dd>
                    </div>
                </dl>

                <p class="mt-5 border-t border-hairline pt-4 text-caption text-content-muted">
                    Harga beli terakhir terisi otomatis setiap kali ada Inbound yang menyertakan harga.
                    Lead time diisi manual karena bergantung hasil kesepakatan. Bila produk belum punya
                    pemasok, sistem memakai asumsi lead time
                    {{ defaults.default_lead_time_days }} hari saat menghitung reorder point.
                </p>
            </aside>
        </div>
    </AppLayout>
</template>