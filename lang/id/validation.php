<?php

/*
|--------------------------------------------------------------------------
| Pesan validasi (Indonesia)
|--------------------------------------------------------------------------
|
| SENGJA TIDAK LENGKAP. Laravel hanya memakai berkas ini untuk kunci yang ada
| di dalamnya, lalu jatuh ke APP_FALLBACK_LOCALE untuk kunci yang tidak ada.
| Jadi cukup tulis aturan yang benar-benar dipakai aplikasi ini; sisanya tetap
| berbahasa Inggris tanpa membuat aplikasi gagal berjalan.
|
| Daftar di bawah mengikuti aturan yang muncul dari seluruh FormRequest,
| From::validate() di controller, dan validasi bawaan Breeze.
|
*/

return [
    'accepted' => ':attribute harus disetujui.',
    'after' => ':attribute harus berupa tanggal setelah :date.',
    'array' => ':attribute harus berupa larik.',
    'before' => ':attribute harus berupa tanggal sebelum :date.',
    'boolean' => ':attribute harus berupa benar atau salah.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'current_password' => 'Password salah.',
    'date' => ':attribute bukan tanggal yang valid.',
    'date_format' => ':attribute tidak sesuai format :format.',
    'decimal' => ':attribute harus memiliki paling banyak :decimal angka di belakang koma.',
    'different' => ':attribute dan :other harus berbeda.',
    'email' => ':attribute harus berupa alamat email yang valid.',
    'exists' => ':attribute yang dipilih tidak valid.',
    'file' => ':attribute harus berupa berkas.',
    'filled' => ':attribute wajib diisi.',
    'image' => ':attribute harus berupa gambar.',
    'in' => ':attribute yang dipilih tidak valid.',
    'integer' => ':attribute harus berupa bilangan bulat.',
    'max' => [
        'array' => ':attribute tidak boleh lebih dari :max item.',
        'file' => ':attribute tidak boleh lebih besar dari :max kilobyte.',
        'numeric' => ':attribute tidak boleh lebih dari :max.',
        'string' => ':attribute tidak boleh lebih dari :max karakter.',
    ],
    'mimes' => ':attribute harus berupa berkas berjenis: :values.',
    'min' => [
        'array' => ':attribute harus memiliki minimal :min item.',
        'file' => ':attribute harus minimal :min kilobyte.',
        'numeric' => ':attribute minimal :min.',
        'string' => ':attribute minimal :min karakter.',
    ],
    'numeric' => ':attribute harus berupa angka.',
    'present' => ':attribute wajib ada.',
    'required' => ':attribute wajib diisi.',
    'required_if' => ':attribute wajib diisi bila :other bernilai :value.',
    'required_with' => ':attribute wajib diisi bila :values ada.',
    'same' => ':attribute dan :other harus sama.',
    'string' => ':attribute harus berupa teks.',
    'unique' => ':attribute sudah digunakan.',
    'url' => ':attribute harus berupa URL yang valid.',

    'custom' => [
        // Placeholder yang diganti::<attribute> dan :value perFormRequest.
        'supplier_id' => [
            'exists' => 'Pemasok yang dipilih tidak ada.',
        ],
        'suppliers' => [
            'exists' => 'Ada pemasok yang sudah tidak ada.',
        ],
    ],

    'attributes' => [],
];
