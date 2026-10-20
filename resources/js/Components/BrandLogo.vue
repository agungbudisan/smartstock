<script setup>
/**
 * Logo horizontal aplikasi.
 *
 * Dipakai di tiga tempat: header sidebar (desktop), topbar (ponsel), dan
 * halaman Auth. Ketiganya sebelumnya menampilkan logo persegi + teks
 * "SmartStock". Teks itu sengaja dihapus, bukan disembunyikan: logo yang
 * sudah memuat nama, diletakkan di samping nama yang sama, akan terlihat
 * ganda.
 *
 * Berkas gambarnya di `public/brand/`, bukan di `resources/`, supaya tidak
 * ikut diproses Vite. Logo adalah aset statis yang namanya sudah disebut
 * langsung di dalam HTML dan di `app.blade.php` untuk favicon, jadi Vite
 * tidak menambah apa pun di sini selain nama berhash yang tidak bisa
 * dirujuk manual.
 *
 * `object-contain` ada di dalam komponen, bukan di pemanggil: berkas PNG
 * bisa punya rasio sedikit berbeda dari yang dijanjikan, dan tanpa itu
 * logo akan tertekan begitu lebarnya dibatasi oleh flex.
 */
</script>

<template>
    <img src="/brand/logo-horizontal.png?v=20261009" alt="SmartStock" class="object-contain" />
</template>
