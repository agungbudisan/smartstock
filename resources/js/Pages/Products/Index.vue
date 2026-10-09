<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    products: { type: Object, required: true },
    categories: { type: Array, required: true },
    filters: { type: Object, required: true },
});

const search = ref(props.filters.search ?? '');
const categoryId = ref(props.filters.category_id ?? '');
const stock = ref(props.filters.stock ?? 'all');
const trashed = ref(props.filters.trashed ?? false);

let timer;

function apply() {
    router.get(
        '/products',
        {
            search: search.value || null,
            category_id: categoryId.value || null,
            stock: stock.value,
            trashed: trashed.value ? 1 : null,
        },
        { preserveState: true, replace: true }
    );
}

// Debounce agar tidak memicu request pada setiap ketikan.
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(apply, 350);
});

watch([categoryId, stock, trashed], apply);

function archive(id) {
    if (confirm('Arsipkan produk ini? Data tidak dihapus permanen dan bisa dipulihkan.')) {
        router.delete(`/products/${id}`);
    }
}

function restore(id) {
    router.post(`/products/${id}/restore`);
}
</script>

<template>
    <Head title="Produk" />

    <AppLayout title="Master Produk">
        <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="overline">Master Data</p>
                <h1 class="mt-1 text-section">Katalog Produk</h1>
            </div>

            <Link href="/products/create" class="btn-primary">Tambah Produk</Link>
        </div>

        <div class="mb-6 flex flex-wrap items-center gap-3">
            <!--
                `basis-full` di layar kecil: `min-w-64` tanpa itu memaksa kotak
                pencarian tetap selebar 256px dan menyisakan sangat sedikit ruang
                bagi dropdown di sampingnya.
            -->
            <div class="min-w-0 flex-1 basis-full sm:min-w-64 sm:basis-auto">
                <input
                    v-model="search"
                    type="search"
                    placeholder="Cari nama atau SKU..."
                    class="input"
                />
            </div>

            <select v-model="categoryId" class="input w-auto">
                <option value="">Semua kategori</option>
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>

            <select v-model="stock" class="input w-auto">
                <option value="all">Semua stok</option>
                <option value="low">Perlu reorder</option>
                <option value="out">Stok habis</option>
            </select>

            <label class="flex cursor-pointer items-center gap-2 text-small">
                <input
                    v-model="trashed"
                    type="checkbox"
                    class="rounded-full border-gray-300 text-primary focus:ring-primary"
                />
                Tampilkan arsip
            </label>
        </div>

        <section class="card overflow-hidden">
            <p
                v-if="!products.data.length"
                class="px-6 py-12 text-center text-small text-content-muted"
            >
                Tidak ada produk yang cocok dengan filter ini.
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
                            <th class="px-3 py-3 sm:px-6 text-overline">SKU</th>
                            <th class="px-3 py-3 sm:px-6 text-overline">Nama</th>
                            <th class="px-3 py-3 sm:px-6 text-overline">Kategori</th>
                            <th class="px-3 py-3 sm:px-6 text-overline text-right">Stok</th>
                            <th class="px-3 py-3 sm:px-6 text-overline text-right">Batas Aman</th>
                            <th class="px-3 py-3 sm:px-6 text-overline">Status</th>
                            <th class="px-3 py-3 sm:px-6 text-overline text-right">Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="product in products.data" :key="product.id" class="table-row">
                            <td class="px-3 py-4 font-mono text-small sm:px-6">{{ product.sku }}</td>

                            <td class="px-3 py-4 text-small font-medium sm:px-6">
                                {{ product.name }}
                            </td>

                            <td class="px-3 py-4 text-small text-content-secondary sm:px-6">
                                {{ product.category?.name ?? 'Tanpa kategori' }}
                            </td>

                            <td class="px-3 py-4 text-right font-display text-subhead sm:px-6">
                                {{ product.current_stock.toLocaleString('id-ID') }}
                            </td>

                            <td class="px-3 py-4 text-right text-small text-content-secondary sm:px-6">
                                {{ product.reorder_point.toLocaleString('id-ID') }}
                            </td>

                            <td class="px-3 py-4 sm:px-6">
                                <span v-if="product.deleted_at" class="chip-danger">Diarsipkan</span>
                                <span v-else-if="product.current_stock === 0" class="chip-danger">
                                    Habis
                                </span>
                                <span v-else-if="product.needs_reorder" class="chip-warning">
                                    Perlu reorder
                                </span>
                                <span v-else class="chip-success">Aman</span>
                            </td>

                            <td class="px-3 py-4 text-right sm:px-6">
                                <button
                                    v-if="product.deleted_at"
                                    type="button"
                                    class="btn-ghost btn-sm"
                                    @click="restore(product.id)"
                                >
                                    Pulihkan
                                </button>

                                <template v-else>
                                    <Link
                                        :href="`/products/${product.id}/edit`"
                                        class="btn-ghost btn-sm"
                                    >
                                        Ubah
                                    </Link>
                                    <button
                                        type="button"
                                        class="btn-ghost btn-sm text-danger"
                                        @click="archive(product.id)"
                                    >
                                        Arsip
                                    </button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="products.last_page > 1" class="border-t border-hairline px-6 py-4">
                <nav class="flex items-center justify-between text-small">
                    <span class="text-content-muted">
                        Halaman {{ products.current_page }} dari {{ products.last_page }} ({{
                            products.total
                        }}
                        produk)
                    </span>

                    <div class="flex gap-2">
                        <a
                            v-if="products.prev_page_url"
                            :href="products.prev_page_url"
                            class="btn-secondary btn-sm"
                        >
                            Sebelumnya
                        </a>
                        <a
                            v-if="products.next_page_url"
                            :href="products.next_page_url"
                            class="btn-secondary btn-sm"
                        >
                            Berikutnya
                        </a>
                    </div>
                </nav>
            </div>
        </section>
    </AppLayout>
</template>