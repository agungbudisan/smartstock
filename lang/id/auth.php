<?php

/*
|--------------------------------------------------------------------------
| Baris autentikasi (Indonesia)
|--------------------------------------------------------------------------
|
| Dipakai ketika login gagal, akun terkunci, dan throttle. Tanpa berkas ini,
| pesan "These credentials do not match our records." tampil dalam bahasa
| Inggris padahal seluruh antarmuka lain berbahasa Indonesia.
|
*/

return [
    'failed' => 'Email atau password tidak cocok dengan data kami.',
    'password' => 'Password yang dimasukkan salah.',
    'throttle' => 'Terlalu banyak percobaan masuk. Silakan coba lagi dalam :seconds detik.',
];
