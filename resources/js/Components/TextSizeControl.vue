<script setup>
import { onMounted, ref } from 'vue';

/**
 * Pengatur ukuran teks.
 *
 * Seluruh skala tipografi memakai `rem`, jadi pengatur ini cukup mengubah satu
 * atribut pada elemen `<html>` dan seluruh aplikasi membesar bersamaan: teks,
 * jarak, tinggi baris tabel, lebar sidebar. Tidak ada komponen lain yang perlu
 * ikut diubah.
 *
 * Dipisah jadi komponen karena dipakai di dua tempat -- menu user di sidebar
 * dan menu user di drawer ponsel. Dua salinan akan berbeda begitu ada
 * penambahan opsi.
 *
 * Opsi ditata VERTIKAL, bukan baris. Menu user di sidebar selebar 12rem, dan
 * tiga tombol bersebelahan dengan label seperti "Sangat besar" meluber dari
 * kotak itu bahkan pada ukuran normal -- makin meluber saat pengguna memilih
 * ukuran yang lebih besar. Susunan vertikal tidak punya batas lebar sama
 * sekali, jadi aman di semua tingkat ukuran dan semua lebar menu.
 *
 * Keadaan awal dibaca dari DOM, bukan dari `localStorage`, karena atributnya
 * sudah dipasang oleh skrip pra-render di `app.blade.php`. Membaca
 * `localStorage` di sini berarti dua sumber kebenaran yang bisa berbeda kalau
 * skrip itu gagal karena storage diblokir.
 */
const OPSI = [
    { nilai: 'normal', label: 'Normal' },
    { nilai: 'besar', label: 'Besar' },
    { nilai: 'sangat-besar', label: 'Sangat besar' },
];

const KUNCI = 'smartstock.ukuran-teks';

const terpilih = ref('normal');

onMounted(() => {
    terpilih.value = document.documentElement.getAttribute('data-ukuran-teks') ?? 'normal';
});

function pilih(nilai) {
    terpilih.value = nilai;

    if (nilai === 'normal') {
        document.documentElement.removeAttribute('data-ukuran-teks');
    } else {
        document.documentElement.setAttribute('data-ukuran-teks', nilai);
    }

    try {
        localStorage.setItem(KUNCI, nilai);
    } catch (e) {
        // Storage diblokir. Pilihan tetap berlaku untuk sesi ini; hanya tidak
        // diingat setelah halaman ditutup.
    }
}
</script>

<template>
    <div>
        <p class="mb-1.5 px-2.5 text-caption font-medium text-content-secondary">Ukuran teks</p>

        <div class="space-y-0.5" role="group" aria-label="Ukuran teks">
            <button
                v-for="opsi in OPSI"
                :key="opsi.nilai"
                type="button"
                class="flex w-full items-center justify-between gap-2 rounded-input px-2.5 py-1.5 text-left text-small transition"
                :class="
                    terpilih === opsi.nilai
                        ? 'bg-primary/10 font-medium text-primary'
                        : 'text-content-secondary hover:bg-surface-muted hover:text-content'
                "
                :aria-pressed="terpilih === opsi.nilai"
                @click="pilih(opsi.nilai)"
            >
                <span class="truncate">{{ opsi.label }}</span>

                <svg
                    v-if="terpilih === opsi.nilai"
                    class="h-4 w-4 shrink-0"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2.5"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" />
                </svg>
            </button>
        </div>

        <p class="mt-2 px-2.5 text-caption text-content-muted">
            Mengikuti pengaturan ukuran font browser Anda.
        </p>
    </div>
</template>