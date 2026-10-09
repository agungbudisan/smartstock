<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';

/**
 * Lonceng notifikasi di header.
 *
 * Data diambil dari prop global `notifications` yang dibagikan
 * HandleInertiaRequests ke semua halaman, jadi komponen ini tidak perlu
 * request sendiri dan otomatissinkron setelah navigasi Inertia.
 */
const page = usePage();
const open = ref(false);
const root = ref(null);

const state = computed(() => page.props.notifications ?? { unread_count: 0, items: [] });
const items = computed(() => state.value.items ?? []);
const unread = computed(() => state.value.unread_count ?? 0);
const hasUnread = computed(() => unread.value > 0);

const toneClass = {
    danger: 'bg-danger',
    warning: 'bg-amber-500',
};

function markAll() {
    router.patch('/notifications/read-all', {}, { preserveScroll: true });
    open.value = false;
}

function openItem(item) {
    open.value = false;

    if (item.read) {
        router.visit(item.action_url, { preserveScroll: true });

        return;
    }

    router.patch(`/notifications/${item.id}/read`, {}, { preserveScroll: true });
}

function relativeTime(iso) {
    if (!iso) return '';

    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);

    if (diff < 60) return 'baru saja';
    if (diff < 3600) return `${Math.floor(diff / 60)} menit lalu`;

    const hours = Math.floor(diff / 3600);

    if (hours < 24) return `${hours} jam lalu`;

    return `${Math.floor(hours / 24)} hari lalu`;
}

// Klik di luar dropdown menutupnya.
function onDocumentClick(event) {
    if (root.value && !root.value.contains(event.target)) {
        open.value = false;
    }
}

onUnmounted(() => document.removeEventListener('click', onDocumentClick));

watch(open, (isOpen) => {
    if (isOpen) {
        document.addEventListener('click', onDocumentClick);
    } else {
        document.removeEventListener('click', onDocumentClick);
    }
});
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="relative flex h-9 w-9 items-center justify-center rounded-input text-content-secondary transition hover:bg-surface hover:text-content"
            :aria-label="`Notifikasi${hasUnread ? `, ${unread} belum dibaca` : ''}`"
            :aria-expanded="open"
            @click="open = !open"
        >
            <svg
                class="h-5 w-5"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
                viewBox="0 0 24 24"
                aria-hidden="true"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"
                />
            </svg>

            <span
                v-if="hasUnread"
                class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-danger px-1 text-overline font-medium text-white"
            >
                {{ unread > 99 ? '99+' : unread }}
            </span>
        </button>

        <div
            v-if="open"
            class="absolute right-0 z-50 mt-2 w-96 max-w-[calc(100vw-2rem)] overflow-hidden rounded-card border border-hairline bg-surface shadow-card-hover"
        >
            <div class="flex items-center justify-between border-b border-hairline px-4 py-3">
                <p class="text-small font-medium">Notifikasi</p>
                <button
                    v-if="hasUnread"
                    type="button"
                    class="text-caption text-primary hover:underline"
                    @click="markAll"
                >
                    Tandai semua dibaca
                </button>
            </div>

            <p v-if="!items.length" class="px-4 py-8 text-center text-small text-content-muted">
                Belum ada notifikasi.
            </p>

            <ul v-else class="max-h-96 divide-y divide-hairline overflow-y-auto">
                <li v-for="item in items" :key="item.id">
                    <button
                        type="button"
                        class="flex w-full items-start gap-3 px-4 py-3 text-left transition hover:bg-surface-muted"
                        @click="openItem(item)"
                    >
                        <span
                            class="mt-1.5 h-2 w-2 shrink-0 rounded-full"
                            :class="item.read ? 'bg-transparent' : (toneClass[item.tone] ?? 'bg-primary')"
                        />

                        <span class="min-w-0 flex-1">
                            <span class="block text-small font-medium">{{ item.title }}</span>
                            <span class="mt-0.5 block text-caption text-content-secondary">
                                {{ item.message }}
                            </span>
                            <span class="mt-1 block text-caption text-content-muted">
                                {{ relativeTime(item.created_at) }}
                            </span>
                        </span>
                    </button>
                </li>
            </ul>

            <div v-if="items.length" class="border-t border-hairline px-4 py-2.5">
                <Link href="/products" class="text-caption text-content-secondary hover:text-primary" @click="open = false">
                    Lihat katalog produk
                </Link>
            </div>
        </div>
    </div>
</template>