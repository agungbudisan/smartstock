<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        {{--
            Ikon tab dan ikon homescreen.

            Tag eksplisit karena `public/favicon.ico` dulunya 0 byte: peramban
            tetap memintanya, menerima berkas kosong, lalu menampilkan ikon
            generik. Menambahkan tag tanpa memperbaiki berkasnya tidak akan
            mengubah apa pun.
        --}}
        <link rel="icon" href="/favicon.ico?v=20261009" sizes="any">
        <link rel="apple-touch-icon" href="/brand/apple-touch-icon.png?v=20261009">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!--
            Pengatur ukuran teks, diterapkan SEBELUM halaman digambar.

            Kalau atribut ini baru dipasang setelah Vue selesai mount, seluruh
            aplikasi akan terlihat dalam ukuran lama selama satu frame atau dua
            dan kemudian meloncat. Untuk pengguna yang membuka aplikasi setiap
            hari dengan ukuran besar, kedipan itu terasa setiap kali.
        -->
        <script>
            (function () {
                try {
                    var tersimpan = localStorage.getItem('smartstock.ukuran-teks');
                    if (tersimpan === 'besar' || tersimpan === 'sangat-besar') {
                        document.documentElement.setAttribute('data-ukuran-teks', tersimpan);
                    }
                } catch (e) {
                    // localStorage bisa diblokir (mode privasi, iframe tanpa
                    // izin). Ukuran teks hanya hak akses, jadi kegagalan di sini
                    // tidak boleh menghentikan halaman.
                }
            })();
        </script>

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
