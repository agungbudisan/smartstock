<?php

/*
|--------------------------------------------------------------------------
| Pesan pengaturan ulang password (Indonesia)
|--------------------------------------------------------------------------
|
| `passwords.php` bawaan Laravel memakai placeholder "RESET_PASSWORD_LINK" yang
| menyisipkan tautan. Disalin apa adanya supaya email reset password berbahasa
| Indonesia tanpa perlu customizing notifikasi.
|
*/

return [
    'reset' => 'Password Anda sudah berhasil diatur ulang.',
    'sent' => 'Tautan pengaturan ulang password sudah kami kirim ke email Anda.',
    'throttled' => 'Terlalu banyak permintaan. Silakan coba lagi dalam :seconds detik.',
    'token' => 'Token pengaturan ulang password ini sudah tidak berlaku.',
    'user' => 'Tidak ada akun yang terdaftar dengan alamat email tersebut.',
];
