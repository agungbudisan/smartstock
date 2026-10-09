<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Struktur Navigasi
    |--------------------------------------------------------------------------
    |
    | Menu-sidebar hidup di PHP, bukan di komponen Vue. Alasannya, menu adalah
    | bagian dari aplikasi yang paling sering berubah sementara paling sering
    | diperiksa: menambah modul berarti menambah item, dan item yang tidak
    | pernah diuji adalah item yang bisa saja tidak bisa dibuka oleh perannya.
    |
    | Dengan bentuknya di sini, `NavigationReachableTest` cukup memuat file ini
    | sebagai PHP biasa dan menjalankan `GET` ke setiap href sebagai pengguna
    | peran itu. Sebelumnya test itu harus mem-parse teks `AppLayout.vue`,
    | yang rapuh: begitu susunan JS-nya berubah -- bukan karena ada bug -- test
    | gagal tanpa ada yang salah di aplikasi.
    |
    | Setiap grup adalah `{ heading, items }`. `heading` boleh null untuk grup
    | pertama yang tidak perlu judul -- penanda bahwa isi grup itu sudah jelas
    | dari posisinya.
    |
    | `roles` pada sebuah item membatasi siapa yang melihatnya. Tidak diisi
    | berarti semua peran. Ini yang jadi sumber kebenaran SOAL KETERBUKAAN --
    | `NavigationReachableTest` memakainya untuk memeriksa setiap href, jadi
    | halaman yang tidak bisa dibuka sebuah peran tidak akan pernah muncul di
    | sidebar perannya.
    |
    | `icon` adalah nama, bukan markup. SVG-nya ada di `SidebarNav.vue` supaya
    | komponen itu tetap tidak bergantung pada HTML mentah dari server.
    |
    */

    'groups' => [

        'operasional' => [
            'heading' => 'Operasional',
            'items' => [
                [
                    'label' => 'Pergerakan Stok',
                    'href' => '/inventory/movements',
                    'icon' => 'swap',
                    'roles' => ['admin_gudang'],
                ],
                ['label' => 'Buku Besar Stok', 'href' => '/inventory/ledger', 'icon' => 'ledger'],
                ['label' => 'Jejak Audit', 'href' => '/inventory/audit-logs', 'icon' => 'audit'],
            ],
        ],

        'master_data' => [
            'heading' => 'Data Master',
            'items' => [
                ['label' => 'Produk', 'href' => '/products', 'icon' => 'box'],
                ['label' => 'Kategori', 'href' => '/categories', 'icon' => 'tag'],
                ['label' => 'Pemasok', 'href' => '/suppliers', 'icon' => 'truck'],
            ],
        ],

        'analitik' => [
            'heading' => 'Analitik',
            'items' => [
                ['label' => 'Dasbor', 'href' => '/analytics', 'icon' => 'chart'],
                ['label' => 'Evaluasi Akurasi', 'href' => '/analytics/accuracy', 'icon' => 'target'],
                ['label' => 'Reorder Point', 'href' => '/analytics/reorder', 'icon' => 'alert'],
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Menu per Peran
    |--------------------------------------------------------------------------
    |
    | Daftar grup yang TAMPIL untuk tiap peran, berurutan dari atas.
    |
    | Perhatikan apa yang tidak ada di sini: entah satu pun peran yang punya
    | menu berisi halaman form yang tidak bisa ia simpan. "Pergerakan Stok"
    | hanya untuk Admin Gudang, dan Manager punya "Buku Besar Stok" sebagai
    | padanannya yang baca-saja. Mengubah daftar ini berarti mengubah RBAC
    | yang terlihat oleh pengguna -- `NavigationReachableTest` akan langsung
    | gagal kalau sebuah halaman tidak bisa dibuka oleh perannya.
    |
    */

    'per_role' => [

        'admin_gudang' => ['operasional', 'master_data'],

        'manager_bisnis' => ['analitik', 'operasional', 'master_data'],

    ],

];
