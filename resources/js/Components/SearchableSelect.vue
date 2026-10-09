<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Dropdown yang bisa diketik untuk dicari.
 *
 * `<select>` native tidak punya pencarian. Dengan puluhan produk, operator
 * harus MEMBACA daftar itu satu per satu, dan daftar hanya bisa dibuka sekali,
 * jadi posisi "yang kira-kira di tengah" harus ditemukan ulang setiap kali
 * menambah baris baru.
 *
 * Kontraknya sengaja dangkal: pemanggil mengirim opsi yang sudah berisi
 * `{ id, label, hint? }`. Komponen ini tidak tahu apa itu SKU, stok, atau
 * pemasok, jadi dropdown pemasok di halaman yang sama bisa memakainya nanti
 * tanpa perubahan apa pun di sini.
 *
 * Hasil pencarian diperingkat: yang diketik persis atau yang berawalan sama
 * muncul lebih dulu. Tanpa itu, mengetik "ELK" bisa memunculkan lebih dulu
 * produk yang namanya mengandung kata tertentu, dan orang harus menggulir
 * melewati semuanya sebelum menemukan yang dicari.
 */
const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    options: { type: Array, required: true },
    placeholder: { type: String, default: 'Pilih...' },
    emptyText: { type: String, default: 'Tidak ada hasil.' },
    disabled: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    // Batas hasil yang ditampilkan sekaligus. Daftar yang terlalu panjang
    // memperlambat: orang harus menggulir ratusan baris untuk menemukan satu.
    limit: { type: Number, default: 60 },
});

const emit = defineEmits(['update:modelValue']);

const terbuka = ref(false);
const query = ref('');
const sorot = ref(0);
const akar = ref(null);
const inputEl = ref(null);

const terpilih = computed(
    () => props.options.find((o) => String(o.id) === String(props.modelValue)) ?? null,
);

/** Yang tampil di kolom input: label pilihan, atau ketikan yang sedang jalan. */
const teks = computed(() => (terpilih.value ? terpilih.value.label : query.value));

const hasil = computed(() => {
    const q = query.value.trim().toLowerCase();

    if (q === '') {
        return props.options;
    }

    const persis = [];
    const awal = [];
    const memuat = [];

    for (const opsi of props.options) {
        const label = String(opsi.label ?? '').toLowerCase();
        const hint = String(opsi.hint ?? '').toLowerCase();
        const id = String(opsi.id ?? '').toLowerCase();

        if (label === q || id === q || hint === q) {
            persis.push(opsi);
        } else if (label.startsWith(q) || id.startsWith(q) || hint.startsWith(q)) {
            awal.push(opsi);
        } else if (label.includes(q) || id.includes(q) || hint.includes(q)) {
            memuat.push(opsi);
        }
    }

    return [...persis, ...awal, ...memuat];
});

const tampil = computed(() => hasil.value.slice(0, props.limit));
const adaLebih = computed(() => hasil.value.length > tampil.value.length);

function hash(teks) {
    let h = 0;
    for (let i = 0; i < teks.length; i++) {
        h = (h * 31 + teks.charCodeAt(i)) | 0;
    }

    return Math.abs(h);
}

/**
 * Id opsi unik per baris form. Tanpa ini, beberapa instance di satu halaman
 * menghasilkan id yang sama dan `aria-activedescendant` menunjuk ke opsi milik
 * instance lain.
 */
function idOpsi(index) {
    return `opsi-${hash(String(props.modelValue ?? '') + teks.value)}-${index}`;
}

function buka() {
    if (props.disabled) {
        return;
    }

    terbuka.value = true;
    query.value = '';
    sorot.value = 0;

    // Teks diinput dipilih seluruhnya, jadi ketikan baru menggantikannya dan
    // bukan menempelkan di belakang teks lama.
    nextTick(() => inputEl.value?.select());
}

function tutup() {
    terbuka.value = false;
    query.value = '';
    sorot.value = 0;
}

function pilih(opsi) {
    emit('update:modelValue', opsi.id);
    tutup();
}

function bersihkan() {
    emit('update:modelValue', '');
    tutup();
    inputEl.value?.focus();
}

function geser(arah) {
    const jumlah = tampil.value.length;

    if (jumlah === 0) {
        return;
    }

    sorot.value = (sorot.value + arah + jumlah) % jumlah;

    nextTick(() => {
        akar.value?.querySelector(`#${idOpsi(sorot.value)}`)?.scrollIntoView({ block: 'nearest' });
    });
}

function onKeydown(event) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        terbuka.value ? geser(1) : buka();

        return;
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        geser(-1);

        return;
    }

    if (event.key === 'Enter') {
        // Tanpa preventDefault, Enter di dalam form akan mengirim form: memilih
        // opsi sekaligus mencoba menyimpan faktur.
        if (terbuka.value && tampil.value[sorot.value]) {
            event.preventDefault();
            pilih(tampil.value[sorot.value]);
        }

        return;
    }

    if (event.key === 'Escape') {
        tutup();
    }
}

/**
 * Klik di luar menutup, dipasang di `document` karena daftar ini berada di
 * halaman panjang dan bisa keluar viewport.
 *
 * `@blur` di input tidak cukup: klik pada opsi memicu blur lebih dulu, yang
 * menutup daftar sebelum event klik-nya sempat sampai. Karena itu opsi memakai
 * `@mousedown.prevent` tanpa handler -- pointer tetap di dalam input, dan event
 * click tetap terjadi.
 */
function onKlikLuar(event) {
    if (terbuka.value && akar.value && !akar.value.contains(event.target)) {
        tutup();
    }
}

onMounted(() => document.addEventListener('click', onKlikLuar));
onBeforeUnmount(() => document.removeEventListener('click', onKlikLuar));

/**
 * Kalau pilihan sekarang tidak ada lagi di `options`, hapus.
 *
 * Daftar opsi bisa berubah: produk yang sudah terpakai di baris lain dikeluarkan,
 * produk diarsipkan hilang, atau CreatePage dibuka ulang. Menahan pilihan yang
 * sudah tidak berlaku lebih buruk daripada mengosongkan, karena operator akan
 * mengira barang yang tidak ada di daftar masih valid dan tercatat di faktur.
 */
watch(
    () => props.options,
    () => {
        const adaNilai =
            props.modelValue !== '' && props.modelValue !== null && props.modelValue !== undefined;

        if (adaNilai && !terpilih.value) {
            emit('update:modelValue', '');
        }

        if (sorot.value >= tampil.value.length) {
            sorot.value = 0;
        }
    },
    { deep: true },
);
</script>

<template>
    <div ref="akar" class="relative">
        <div class="relative">
            <input
                ref="inputEl"
                :value="teks"
                type="text"
                class="input pr-10"
                :class="{ 'input-error': invalid }"
                :placeholder="placeholder"
                :disabled="disabled"
                :required="required"
                :aria-expanded="terbuka"
                autocomplete="off"
                role="combobox"
                aria-autocomplete="list"
                @focus="buka"
                @input="query = $event.target.value; terbuka = true; sorot = 0"
                @keydown="onKeydown"
            />

            <button
                v-if="terpilih && !disabled"
                type="button"
                class="absolute right-8 top-1/2 -translate-y-1/2 rounded-input p-1 text-content-muted hover:bg-surface-muted hover:text-content"
                aria-label="Kosongkan pilihan"
                @click="bersihkan"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
                </svg>
            </button>

            <svg
                class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-content-muted"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2"
                aria-hidden="true"
            >
                <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
            </svg>
        </div>

        <ul
            v-if="terbuka"
            class="absolute z-50 mt-1 max-h-72 w-full overflow-y-auto rounded-panel border border-hairline bg-surface py-1 shadow-card-hover"
            role="listbox"
        >
            <li v-if="!tampil.length" class="px-3.5 py-2.5 text-small text-content-muted">
                {{ emptyText }}
            </li>

            <li
                v-for="(opsi, index) in tampil"
                :id="idOpsi(index)"
                :key="opsi.id"
                class="cursor-pointer px-3.5 py-2"
                :class="index === sorot ? 'bg-primary/10' : ''"
                role="option"
                :aria-selected="index === sorot"
                @mousedown.prevent
                @click="pilih(opsi)"
            >
                <p class="truncate text-small text-content">{{ opsi.label }}</p>
                <p v-if="opsi.hint" class="truncate text-caption text-content-muted">{{ opsi.hint }}</p>
            </li>

            <li
                v-if="adaLebih"
                class="border-t border-hairline px-3.5 py-2 text-caption text-content-muted"
            >
                {{ hasil.length }} hasil cocok. Ketik lebih lanjut untuk mempersempit.
            </li>
        </ul>
    </div>
</template>