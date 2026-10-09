<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

/**
 * Daftar menu sidebar, dipakai dua kali: di sidebar desktop dan di drawer
 * mobile.
 *
 * Dipisah jadi komponen karena keduanya menampilkan daftar yang PERSIS sama.
 * Dua salinan akan berbeda begitu ada perbaikan di salah satunya — dan
 * perbedaannya baru terasa saat salah satunya menampilkan menu yang sudah usang.
 */
const props = defineProps({
    // Orientasi. `rail` = vertikal di sidebar, `stack` = vertikal di drawer
    // sempit. Keduanya vertikal; pemisahnya cuma lebar kolom ikon.
    compact: { type: Boolean, default: false },
});

const emit = defineEmits(['navigate']);

const page = usePage();

const navigation = computed(() => page.props.navigation ?? []);

/**
 * Ikon garis 24x24, disimpan sebagai daftar `d` -- bukan HTML mentah.
 * Markup dari server tidak pernah ikut ke DOM; frontend yang memilih path-nya
 * dari daftar tetap di bawah file ini.
 */
const ICONS = {
    box: ['M12 3 3.5 7.5v9L12 21l8.5-4.5v-9L12 3Z', 'M3.5 7.5 12 12l8.5-4.5M12 12v9'],
    tag: ['M4 4h6l10 10-6 6L4 10V4Z', 'M7.5 7.5h.01'],
    truck: [
        'M2 6h11v10H2z',
        'M13 9h4l3 3v4h-7z',
        'M6 19a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z',
        'M17 19a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z',
    ],
    swap: ['M4 8h13l-3-3', 'M20 16H7l3 3'],
    ledger: [
        'M8 6h13',
        'M8 12h13',
        'M8 18h13',
        'M3.5 6h.01',
        'M3.5 12h.01',
        'M3.5 18h.01',
    ],
    audit: ['M12 3l7 3v5.5c0 4.5-3 7.5-7 9.5-4-2-7-5-7-9.5V6l7-3Z', 'M9 12l2 2 4-4'],
    chart: ['M3 21h18', 'M6 21v-6', 'M12 21V8', 'M18 21v-10'],
    target: ['M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Z', 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z'],
    alert: ['M12 4 2.5 20h19L12 4Z', 'M12 10v4', 'M12 17h.01'],
};

function iconPaths(name) {
    return ICONS[name] ?? ICONS.box;
}

/**
 * Item aktif adalah yang PALING PANJANG yang cocok, bukan yang pertama.
 *
 * `startsWith('/analytics')` juga benar untuk `/analytics/accuracy`, jadi cara
 * sederhana menyalakan "Dasbor" dan "Evaluasi Akurasi" sekaligus. Di navbar
 * horizontal itu jarang terasa; di sidebar, dengan keduanya berdampingan vertikal,
 * dua baris menyala bersamaan langsung terlihat salah.
 */
const activeHref = computed(() => {
    const path = window.location.pathname.replace(/\/+$/, '') || '/';

    return navigation.value
        .flatMap((group) => group.items)
        .map((item) => item.href)
        .filter((href) => path === href || path.startsWith(`${href}/`))
        .sort((a, b) => b.length - a.length)[0];
});
</script>

<template>
    <div :class="compact ? 'space-y-5' : 'space-y-6'">
        <div v-for="(group, index) in navigation" :key="group.heading ?? `grup-${index}`">
            <p
                v-if="group.heading"
                class="px-3 pb-2 text-overline text-content-muted"
            >
                {{ group.heading }}
            </p>

            <ul class="space-y-0.5">
                <li v-for="item in group.items" :key="item.href">
                    <Link
                        :href="item.href"
                        class="flex items-center gap-3 rounded-input px-3 py-2 text-small font-medium transition"
                        :class="
                            activeHref === item.href
                                ? 'bg-primary/10 text-primary'
                                : 'text-content-secondary hover:bg-surface-muted hover:text-content'
                        "
                        :aria-current="activeHref === item.href ? 'page' : undefined"
                        @click="emit('navigate')"
                    >
                        <svg
                            class="h-5 w-5 shrink-0"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.75"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <path v-for="(d, i) in iconPaths(item.icon)" :key="i" :d="d" />
                        </svg>

                        <span class="truncate">{{ item.label }}</span>
                    </Link>
                </li>
            </ul>
        </div>
    </div>
</template>