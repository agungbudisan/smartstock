<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';

defineProps({
    categories: { type: Array, required: true },
    filters: { type: Object, required: true },
});

const editing = ref(null);

/*
 * `sku_prefix` ikut jadi key form sejak awal, bukan ditambahkan belakangan.
 * useForm().data() mengambil seluruh key objek form, jadi field yang ada di
 * template tapi tidak di sini akan terkirim kosong dan lolos diam-diam.
 * Jauh lebih mudah dijaga kalau form punya satu sumber kebenaran.
 */
const form = useForm({ name: '', sku_prefix: '', description: '' });

function startCreate() {
    editing.value = 'new';
    form.reset();
}

function startEdit(category) {
    editing.value = category.id;
    form.reset();
    form.name = category.name;
    form.description = category.description ?? '';
}

function cancel() {
    editing.value = null;
    form.reset();
}

function save() {
    if (editing.value === 'new') {
        form.post('/categories', { preserveScroll: true, onSuccess: cancel });
    } else {
        form.patch(`/categories/${editing.value}`, { preserveScroll: true, onSuccess: cancel });
    }
}

function remove(category) {
    if (confirm(`Hapus kategori "${category.name}"? Produk terkait tidak ikut terhapus.`)) {
        router.delete(`/categories/${category.id}`, { preserveScroll: true });
    }
}

const errorFor = (field) => form.errors[field];
</script>

<template>
    <Head title="Kategori" />

    <AppLayout title="Kategori Produk">
        <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="overline">Master Data</p>
                <h1 class="mt-1 text-section">Kategori</h1>
            </div>

            <button v-if="editing === null" type="button" class="btn-primary" @click="startCreate">
                Tambah Kategori
            </button>
        </div>

        <form v-if="editing === 'new'" class="card mb-6 space-y-4 p-6" @submit.prevent="save">
            <div>
                <label class="label" for="new-name">Nama</label>
                <input id="new-name" v-model="form.name" type="text" class="input" required />
                <p v-if="errorFor('name')" class="mt-1 text-caption text-danger">
                    {{ errorFor('name') }}
                </p>
            </div>

            <div>
                <label class="label" for="new-desc">Deskripsi</label>
                <textarea id="new-desc" v-model="form.description" rows="2" class="input" />
            </div>

            <div>
                <label class="label" for="new-prefix">Prefiks SKU (opsional)</label>
                <input
                    id="new-prefix"
                    v-model="form.sku_prefix"
                    type="text"
                    maxlength="8"
                    placeholder="mis. ELK"
                    class="input font-mono"
                />
                <p class="mt-1 text-caption text-content-muted">
                    Dipakai untuk mengisi SKU produk di kategori ini secara otomatis, mis.
                    <span class="font-mono">ELK-005</span>. Kosongkan kalau kategori ini tidak punya
                    pola.
                </p>
                <p v-if="errorFor('sku_prefix')" class="mt-1 text-caption text-danger">
                    {{ errorFor('sku_prefix') }}
                </p>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="btn-primary btn-sm" :disabled="form.processing">
                    Simpan
                </button>
                <button type="button" class="btn-ghost btn-sm" @click="cancel">Batal</button>
            </div>
        </form>

        <section class="card overflow-hidden">
            <p v-if="!categories.length" class="px-6 py-12 text-center text-small text-content-muted">
                Belum ada kategori.
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
                            <th class="px-3 py-3 sm:px-6 text-overline">Nama</th>
                            <th class="px-3 py-3 sm:px-6 text-overline">Deskripsi</th>
                            <th class="px-3 py-3 sm:px-6 text-overline text-right">Produk</th>
                            <th class="px-3 py-3 sm:px-6 text-overline text-right">Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="category in categories" :key="category.id" class="table-row">
                            <template v-if="editing === category.id">
                                <td colspan="4" class="px-3 py-4 sm:px-6">
                                    <form class="space-y-3" @submit.prevent="save">
                                        <input
                                            v-model="form.name"
                                            type="text"
                                            class="input"
                                            required
                                        />
                                        <input
                                            v-model="form.sku_prefix"
                                            type="text"
                                            maxlength="8"
                                            placeholder="Prefiks SKU, mis. ELK"
                                            class="input font-mono"
                                        />
                                        <p
                                            v-if="errorFor('sku_prefix')"
                                            class="text-caption text-danger"
                                        >
                                            {{ errorFor('sku_prefix') }}
                                        </p>
                                        <textarea v-model="form.description" rows="2" class="input" />

                                        <div class="flex gap-2">
                                            <button
                                                type="submit"
                                                class="btn-primary btn-sm"
                                                :disabled="form.processing"
                                            >
                                                Simpan
                                            </button>
                                            <button type="button" class="btn-ghost btn-sm" @click="cancel">
                                                Batal
                                            </button>
                                        </div>
                                    </form>
                                </td>
                            </template>

                            <template v-else>
                                <td class="px-3 py-4 text-small font-medium sm:px-6">
                                    <span class="block">{{ category.name }}</span>
                                    <span
                                        v-if="category.sku_prefix"
                                        class="mt-0.5 block font-mono text-caption text-content-muted"
                                    >
                                        {{ category.sku_prefix }}-...
                                    </span>
                                </td>

                                <td class="px-3 py-4 text-small text-content-secondary sm:px-6">
                                    {{ category.description ?? '-' }}
                                </td>

                                <td class="px-3 py-4 text-right text-small sm:px-6">
                                    {{ category.products_count }}
                                </td>

                                <td class="px-3 py-4 text-right sm:px-6">
                                    <button
                                        type="button"
                                        class="btn-ghost btn-sm"
                                        @click="startEdit(category)"
                                    >
                                        Ubah
                                    </button>
                                    <button
                                        type="button"
                                        class="btn-ghost btn-sm text-danger"
                                        @click="remove(category)"
                                    >
                                        Hapus
                                    </button>
                                </td>
                            </template>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>