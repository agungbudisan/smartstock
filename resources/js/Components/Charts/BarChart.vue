<script setup>
import { computed } from 'vue';

/**
 * Grafik batang sederhana berbasis SVG.
 *
 * Sengaja tanpa pustaka chart eksternal: bentuk datanya sederhana, dan
 * ini menghindari dependensi npm untuk satu visualisasi.
 *
 * Setiap item: `{ label, value, meta?, note? }`.
 * `valueSuffix` / `metaSuffix` menentukan satuan pada tooltip, karena satuan
 * chart berbeda-beda - "pcs" untuk volume, "%" untuk WAPE.
 */
const props = defineProps({
    data: { type: Array, required: true },
    max: { type: Number, default: 0 },
    height: { type: Number, default: 220 },
    valueSuffix: { type: String, default: ' pcs' },
    metaSuffix: { type: String, default: '%' },
    showNote: { type: Boolean, default: false },
});

const maxValue = computed(() => props.max || Math.max(1, ...props.data.map((d) => d.value)));

/**
 * Lebar minimum seluruh grafik, dalam piksel.
 *
 * Ruang per batang (72px) cukup untuk label bulan seperti "Okt 2026" tanpa
 * terpotong; jarak antar-batang 12px sesuai kelas `gap-3`.
 *
 * Tanpa lebar minimum ini, tiap batang memakai `flex-1` dan menyusut bersama
 * wadahnya: di layar 320px dengan lima batang, ruang per batang tinggal sekitar
 * 40px dan label bulan terpotong jadi dua huruf. Memasang `minWidth` membuat
 * grafik bisa digulir horizontal alih-alih dipampitkan sampai tidak terbaca.
 * Makin banyak batang, makin lebar - persis yang dibutuhkan.
 */
function lebarMinimum() {
    const perBatang = 72;
    const jarak = 12;

    return props.data.length * perBatang + Math.max(0, props.data.length - 1) * jarak;
}

function barHeight(value) {
    // Skala akar kuadrat supaya nilai kecil tetap terlihat tanpa sisi negatif
    // yang membuat batang kecil jadi tidak terlihat sama sekali.
    const ratio = maxValue.value === 0 ? 0 : Math.sqrt(value / maxValue.value);

    return Math.max(value > 0 ? 4 : 1, ratio * (props.height - 40));
}

function format(value, suffix) {
    const angka = typeof value === 'number' ? value : Number(value);
    const tampil = Number.isFinite(angka) ? angka.toLocaleString('id-ID') : value;

    return `${tampil}${suffix}`;
}
</script>

<template>
    <div class="overflow-x-auto">
        <div
            class="flex items-end gap-3"
            :style="{ height: `${height}px`, minWidth: `${lebarMinimum()}px` }"
        >
            <div v-for="(item, index) in data" :key="index" class="group flex flex-1 flex-col justify-end">
                <div class="relative flex flex-1 items-end justify-center">
                    <span
                        class="absolute -top-6 whitespace-nowrap rounded-input bg-content px-2 py-1 text-caption text-white opacity-0 transition group-hover:opacity-100"
                    >
                        {{ format(item.value, valueSuffix)
                        }}<span v-if="item.meta !== undefined && item.meta !== null"
                            > · {{ format(item.meta, metaSuffix) }}</span
                        >
                    </span>

                    <div
                        class="w-full max-w-16 rounded-t-input bg-primary/85 transition group-hover:bg-primary"
                        :style="{ height: `${barHeight(item.value)}px` }"
                    />
                </div>

                <p class="mt-2 truncate text-center text-caption text-content-muted">{{ item.label }}</p>
                <p v-if="showNote && item.note" class="truncate text-center text-caption text-amber-600">
                    {{ item.note }}
                </p>
            </div>
        </div>
    </div>
</template>