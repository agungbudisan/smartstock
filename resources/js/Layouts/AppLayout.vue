<script setup>
import BrandLogo from '@/Components/BrandLogo.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import NotificationBell from '@/Components/NotificationBell.vue';
import TextSizeControl from '@/Components/TextSizeControl.vue';
import SidebarNav from '@/Components/Navigation/SidebarNav.vue';
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

/**
 * Shell aplikasi: sidebar tetap di layar lebar, topbar + drawer di layar
 * sempit.
 *
 * Kenapa sidebar, bukan navbar di atas:
 *
 *  - Menu Manager berisi 8 label Indonesia yang panjang ("Evaluasi Akurasi",
 *    "Buku Besar Stok"). Di navbar horizontal semua itu harus muat dalam
 *    ~1280px bersama logo, lonceng, dan avatar -- pas banget, tanpa sisa untuk
 *    satu item lagi. Sidebar memakai sumbu vertikal, yang tidak punya batas
 *    itu.
 *  - Menu sebenarnya bercabang: Operasional, Data Master, dan Analitik adalah
 *    tiga domain berbeda. Navbar hanya bisa meratakan semua jadi teks berurutan;
 *    sidebar bisa memberi judul kelompok, dan urutannya jadi bermakna.
 *  - Aplikasi ini dipakai sepanjang hari. Sidebar membuat navigasi diam di
 *    tempat yang sama, tanpa memaksa mata naik ke atas setiap kali berpindah
 *    modul.
 *
 * Yang DIPERTAHANKAN dari DESIGN.md: bahasa-Abstrak yang sama -- hairline
 * 1px, backdrop-blur pada lapisan yang menempel, token warna dan tipografi
 * yang sama. Yang berubah hanya sumbu navigasinya.
 *
 * Topbar desktop sengaja dihapus. Setiap halaman sudah punya judul sendiri,
 * jadi topbar hanya akan mengulanginya. Header hanya muncul di bawah `lg`.
 */
defineProps({
    title: { type: String, default: '' },
});

const page = usePage();
const user = computed(() => page.props.auth.user);

const roleLabel = computed(() => ({
    admin_gudang: 'Admin Gudang',
    manager_bisnis: 'Manager Bisnis',
}[user.value?.role] ?? user.value?.role));

const mobileOpen = ref(false);

function logout() {
    router.post('/logout');
}
</script>

<template>
    <div class="min-h-screen bg-surface-muted">
        <Head :title="title" />

        <!-- Sidebar desktop -->
        <aside
            class="fixed inset-y-0 left-0 z-40 hidden w-60 flex-col border-r border-hairline bg-surface lg:flex"
        >
            <!--
                `h-12`, bukan `h-7` yang dulu. Logo horizontal berasio 3,33:1,
                jadi tinggi menentukan lebar: 48px menjadi 160px. Dengan
                `h-7` (28px) lebarnya cuma 93px di ruang 200px, dan logo
                terlihat hilang di sidebar 240px.

                Header `h-16` karena 48px logo plus ruang napas harus muat;
                `h-14` hanya memberi 4px di atas dan bawah.
            -->
            <Link
                href="/dashboard"
                class="flex h-16 shrink-0 items-center border-b border-hairline px-5"
            >
                <BrandLogo class="h-12 w-auto shrink-0" />
            </Link>

            <nav class="flex-1 overflow-y-auto px-3 py-5" aria-label="Navigasi utama">
                <SidebarNav />
            </nav>

            <div class="shrink-0 space-y-1 border-t border-hairline p-3">
                <p class="px-3 pb-1 text-overline text-content-muted">{{ roleLabel }}</p>

                <div class="flex items-center gap-1">
                    <NotificationBell />

                    <details class="group relative flex-1">
                        <summary
                            class="flex cursor-pointer list-none items-center gap-2 rounded-input px-2 py-1.5 text-small hover:bg-surface-muted"
                        >
                            <span
                                class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-primary/10 text-caption font-semibold text-primary"
                            >
                                {{ user?.name?.charAt(0)?.toUpperCase() }}
                            </span>
                            <span class="truncate font-medium">{{ user?.name }}</span>
                        </summary>

                        <div
                            class="absolute bottom-full left-0 z-50 mb-1 w-48 overflow-hidden rounded-panel border border-hairline bg-surface shadow-card-hover"
                        >
                            <Link
                                href="/profile"
                                class="block px-4 py-2.5 text-small hover:bg-surface-muted"
                                @click="mobileOpen = false"
                            >
                                Profil
                            </Link>
                            <div class="border-t border-hairline px-4 py-3">
                                <TextSizeControl />
                            </div>
                            <button
                                type="button"
                                class="block w-full px-4 py-2.5 text-left text-small text-danger hover:bg-danger/5"
                                @click="logout"
                            >
                                Keluar
                            </button>
                        </div>
                    </details>
                </div>
            </div>
        </aside>

        <!-- Topbar: hanya layar sempit -->
        <header
            class="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-hairline bg-surface/85 px-4 backdrop-blur lg:hidden"
        >
            <Link href="/dashboard" class="flex items-center">
                <BrandLogo class="h-8 w-auto shrink-0" />
            </Link>

            <div class="ml-auto flex items-center gap-1">
                <NotificationBell />

                <button
                    type="button"
                    class="rounded-input p-2"
                    aria-label="Buka menu"
                    :aria-expanded="mobileOpen"
                    @click="mobileOpen = true"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>
            </div>
        </header>

        <!-- Drawer: hanya layar sempit -->
        <div v-if="mobileOpen" class="lg:hidden">
            <div
                class="fixed inset-0 z-40 bg-content/20"
                aria-hidden="true"
                @click="mobileOpen = false"
            />

            <nav
                class="fixed inset-y-0 left-0 z-50 flex w-72 max-w-[85vw] flex-col bg-surface shadow-card-hover"
                aria-label="Navigasi utama"
            >
                <div class="flex h-14 shrink-0 items-center justify-between border-b border-hairline px-5">
                    <span class="font-display text-subhead tracking-tight">Menu</span>
                    <button
                        type="button"
                        class="rounded-input p-2"
                        aria-label="Tutup menu"
                        @click="mobileOpen = false"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
                        </svg>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto px-3 py-4">
                    <SidebarNav compact @navigate="mobileOpen = false" />
                </div>

                <div class="shrink-0 border-t border-hairline p-3">
                    <Link
                        href="/profile"
                        class="flex items-center gap-2 rounded-input px-2 py-2 text-small hover:bg-surface-muted"
                        @click="mobileOpen = false"
                    >
                        <span
                            class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-primary/10 text-caption font-semibold text-primary"
                        >
                            {{ user?.name?.charAt(0)?.toUpperCase() }}
                        </span>
                        <span class="truncate font-medium">{{ user?.name }}</span>
                    </Link>

                            <div class="border-t border-hairline px-4 py-3">
                                <TextSizeControl />
                            </div>
                    <button
                        type="button"
                        class="mt-1 block w-full rounded-input px-2 py-2 text-left text-small text-danger hover:bg-danger/5"
                        @click="logout"
                    >
                        Keluar
                    </button>
                </div>
            </nav>
        </div>

        <main class="lg:pl-60">
            <div class="mx-auto max-w-container px-4 py-6 sm:px-6 sm:py-8">
                <FlashMessages />
                <slot />
            </div>
        </main>
    </div>
</template>
