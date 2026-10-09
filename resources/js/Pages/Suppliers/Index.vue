<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';

defineProps({
    suppliers: { type: Array, required: true },
    filters: { type: Object, required: true },
});

const editing = ref(null);

const form = useForm({
    name: '',
    contact_person: '',
    phone: '',
    address: '',
});

function startCreate() {
    editing.value = 'new';
    form.reset();
}

function startEdit(supplier) {
    editing.value = supplier.id;
    form.reset();

    Object.assign(form, {
        name: supplier.name,
        contact_person: supplier.contact_person ?? '',
        phone: supplier.phone ?? '',
        address: supplier.address ?? '',
    });
}

function cancel() {
    editing.value = null;
    form.reset();
}

function save() {
    if (editing.value === 'new') {
        form.post('/suppliers', { preserveScroll: true, onSuccess: cancel });
    } else {
        form.patch(`/suppliers/${editing.value}`, { preserveScroll: true, onSuccess: cancel });
    }
}

function remove(supplier) {
    if (confirm(`Hapus pemasok "${supplier.name}"? Riwayat pergerakan tetap tersimpan.`)) {
        router.delete(`/suppliers/${supplier.id}`, { preserveScroll: true });
    }
}

const errorFor = (field) => form.errors[field];
</script>

<template>
    <Head title="Pemasok" />

    <AppLayout title="Pemasok">
        <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="overline">Master Data</p>
                <h1 class="mt-1 text-section">Pemasok</h1>
            </div>

            <button v-if="editing === null" type="button" class="btn-primary" @click="startCreate">
                Tambah Pemasok
            </button>
        </div>

        <form v-if="editing === 'new'" class="card mb-6 space-y-4 p-6" @submit.prevent="save">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="sup-name">Nama</label>
                    <input id="sup-name" v-model="form.name" type="text" class="input" required />
                    <p v-if="errorFor('name')" class="mt-1 text-caption text-danger">
                        {{ errorFor('name') }}
                    </p>
                </div>

                <div>
                    <label class="label" for="sup-contact">Narahubung</label>
                    <input id="sup-contact" v-model="form.contact_person" type="text" class="input" />
                </div>

                <div>
                    <label class="label" for="sup-phone">Telepon</label>
                    <input id="sup-phone" v-model="form.phone" type="text" class="input" />
                </div>

                <div>
                    <label class="label" for="sup-address">Alamat</label>
                    <input id="sup-address" v-model="form.address" type="text" class="input" />
                </div>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="btn-primary btn-sm" :disabled="form.processing">
                    Simpan
                </button>
                <button type="button" class="btn-ghost btn-sm" @click="cancel">Batal</button>
            </div>
        </form>

        <section class="card overflow-hidden">
            <p v-if="!suppliers.length" class="px-6 py-12 text-center text-small text-content-muted">
                Belum ada pemasok terdaftar.
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
                            <th class="px-3 py-3 sm:px-6 text-overline">Narahubung</th>
                            <th class="px-3 py-3 sm:px-6 text-overline">Telepon</th>
                            <th class="px-3 py-3 sm:px-6 text-overline text-right">Produk Disuplai</th>
                            <th class="px-3 py-3 sm:px-6 text-overline text-right">Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="supplier in suppliers" :key="supplier.id" class="table-row">
                            <template v-if="editing === supplier.id">
                                <td colspan="5" class="px-3 py-4 sm:px-6">
                                    <form class="space-y-3" @submit.prevent="save">
                                        <div class="grid gap-3 sm:grid-cols-2">
                                            <input
                                                v-model="form.name"
                                                type="text"
                                                class="input"
                                                required
                                            />
                                            <input
                                                v-model="form.contact_person"
                                                type="text"
                                                class="input"
                                            />
                                            <input v-model="form.phone" type="text" class="input" />
                                            <input v-model="form.address" type="text" class="input" />
                                        </div>

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
                                    {{ supplier.name }}
                                </td>

                                <td class="px-3 py-4 text-small text-content-secondary sm:px-6">
                                    {{ supplier.contact_person ?? '-' }}
                                </td>

                                <td class="px-3 py-4 font-mono text-small sm:px-6">
                                    {{ supplier.phone ?? '-' }}
                                </td>

                                <td class="px-3 py-4 text-right text-small sm:px-6">
                                    {{ supplier.products_count }}
                                </td>

                                <td class="px-3 py-4 text-right sm:px-6">
                                    <button
                                        type="button"
                                        class="btn-ghost btn-sm"
                                        @click="startEdit(supplier)"
                                    >
                                        Ubah
                                    </button>
                                    <button
                                        type="button"
                                        class="btn-ghost btn-sm text-danger"
                                        @click="remove(supplier)"
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