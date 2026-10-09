<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
const props = defineProps({
    logs: { type: Object, required: true },
    filters: { type: Object, required: true },
    actions: { type: Array, required: true },
});
const action = ref(props.filters.action ?? '');
const search = ref(props.filters.search ?? '');
let timer;
function apply() {
    router.get(
        '/inventory/audit-logs',
        { action: action.value || null, search: search.value || null },
        { preserveState: true, replace: true },
    );
}
watch(action, apply);
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(apply, 350);
});
// Warna aksi: hijau untuk inbound, kuning untuk outbound, merah untuk alert.
const toneFor = (value) => {
    if (value.startsWith('inbound')) return 'chip-success';
    if (value.startsWith('outbound')) return 'chip-warning';
    if (value.startsWith('alert')) return 'chip-danger';
    return 'chip-primary';
};
</script>
<template>
    <Head title="Jejak Audit" />
    <AppLayout title="Jejak Audit">
        <div class="mb-8">
            <p class="overline">Audit Trail</p>
            <h1 class="mt-1 text-section">Riwayat Aktivitas</h1>
            <p class="mt-1 text-body text-content-secondary">
                Setiap entri stok dan peringatan otomatis tercatat di sini, bersifat append-only.
            </p>
        </div>
        <div class="mb-6 flex flex-wrap items-center gap-3">
            <select v-model="action" class="input w-auto">
                <option value="">Semua jenis aksi</option>
                <option v-for="a in actions" :key="a" :value="a">{{ a }}</option>
            </select>
            <input v-model="search" type="search" placeholder="Cari deskripsi..." class="input max-w-md" />
        </div>
        <section class="card overflow-hidden">
            <p v-if="!logs.data.length" class="px-6 py-12 text-center text-small text-content-muted">
                Belum ada aktivitas tercatat.
            </p>
            <ul v-else class="divide-y divide-hairline">
                <li v-for="log in logs.data" :key="log.id" class="flex flex-wrap items-start gap-4 px-6 py-4">
                    <span :class="toneFor(log.action)" class="shrink-0 font-mono">{{ log.action }}</span>
                    <p class="min-w-0 flex-1 break-words text-small">{{ log.description }}</p>
                    <div class="text-right">
                        <p class="text-small">{{ log.user?.name ?? 'sistem' }}</p>
                        <p class="text-caption text-content-muted">
                            {{ new Date(log.created_at).toLocaleString('id-ID') }}
                        </p>
                    </div>
                </li>
            </ul>
            <div v-if="logs.last_page > 1" class="border-t border-hairline px-6 py-4">
                <nav class="flex items-center justify-between text-small">
                    <span class="text-content-muted">
                        Halaman {{ logs.current_page }} dari {{ logs.last_page }} ({{ logs.total }} baris)
                    </span>
                    <div class="flex gap-2">
                        <a v-if="logs.prev_page_url" :href="logs.prev_page_url" class="btn-secondary btn-sm">
                            Sebelumnya
                        </a>
                        <a v-if="logs.next_page_url" :href="logs.next_page_url" class="btn-secondary btn-sm">
                            Berikutnya
                        </a>
                    </div>
                </nav>
            </div>
        </section>
    </AppLayout>
</template>
