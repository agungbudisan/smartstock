<script setup>
import ProductForm from '@/Pages/Products/Form.vue';
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    product: { type: Object, required: true },
    categories: { type: Array, required: true },
    suppliersUrl: { type: String, required: true },
    // Diteruskan ke Form hanya supaya komponennya punya prop yang lengkap.
    // Form TIDAK memakainya di mode edit -- SKU yang sudah terbit tidak boleh
    // berubah sendiri karena kategori diubah.
    saranSku: { type: Object, default: () => ({}) },
});

const supplierCount = computed(() => props.product.suppliers?.length ?? 0);

/**
 * Lead time tidak bisa disimpulkan dari riwayat — hanya orang yang tahu
 * berapa lama pemasok mengantar. Karena itu nilainya boleh kosong, tapi kosongnya
 * harus terlihat: ReorderService memakai lead time tercepat untuk menghitung
 * days of cover, dan tanpa angka itu perhitungannya tidak bisa dipercaya.
 */
const tanpaLeadTime = computed(() =>
    (props.product.suppliers ?? []).filter(
        (row) => row.lead_time_days === null || row.lead_time_days === undefined,
    ),
);

const fastestLeadTime = computed(() => {
    const days = (props.product.suppliers ?? [])
        .map((row) => row.lead_time_days)
        .filter((value) => value !== null && value !== undefined);

    return days.length ? Math.min(...days) : null;
});

function formatPrice(value) {
    if (value === null || value === undefined || value === '') {
        return 'harga belum dicatat';
    }

    return 'Rp ' + Number(value).toLocaleString('id-ID');
}
</script>

<template>
    <div>
        <ProductForm
            :product="product"
            :categories="categories"
            :saran-sku="saranSku"
            :submit-url="`/products/${product.id}`"
            method="patch"
        />

        <section class="card mx-auto mt-6 max-w-2xl p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-subhead">Pemasok &amp; Harga Beli</h2>
                    <p class="mt-0.5 text-small text-content-secondary">
                        <template v-if="supplierCount">
                            {{ supplierCount }} pemasok terpasang
                            <template v-if="fastestLeadTime !== null">
                                · lead time tercepat {{ fastestLeadTime }} hari
                            </template>
                        </template>
                        <template v-else>
                            Belum ada pemasok terpasang. Lead time dibutuhkan untuk menghitung reorder point.
                        </template>
                    </p>
                </div>

                <Link :href="suppliersUrl" class="btn-secondary btn-sm shrink-0">
                    {{ supplierCount ? 'Kelola' : 'Tambah' }} Pemasok
                </Link>
            </div>

            <p
                v-if="tanpaLeadTime.length"
                class="mt-4 rounded-input bg-amber-50 px-3.5 py-2.5 text-caption text-amber-700"
            >
                {{ tanpaLeadTime.length }} dari {{ supplierCount }} pemasok belum punya lead time.
                Lead time tidak bisa disimpulkan dari riwayat barang masuk — hanya orang yang tahu
                jaraknya. Sampai diisi, saran "kapan harus pesan" di halaman Reorder memakai
                angka yang belum tentu benar.
            </p>

            <ul v-if="supplierCount" class="mt-4 divide-y divide-hairline">
                <li
                    v-for="supplier in product.suppliers"
                    :key="supplier.id"
                    class="flex items-center justify-between gap-3 py-2.5 text-small"
                >
                    <span>{{ supplier.name }}</span>
                    <span class="text-right">
                        <span
                            class="block text-content-secondary"
                            :class="supplier.lead_time_days === null ? 'text-amber-600' : ''"
                        >
                            {{ supplier.lead_time_days !== null
                                ? 'lead time ' + supplier.lead_time_days + ' hari'
                                : 'lead time belum diisi' }}
                        </span>
                        <span class="block text-caption text-content-muted">
                            {{ formatPrice(supplier.last_purchase_price) }}
                        </span>
                    </span>
                </li>
            </ul>
        </section>
    </div>
</template>