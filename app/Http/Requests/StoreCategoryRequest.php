<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Ubah prefix menjadi huruf kapital sebelum divalidasi.
     *
     * Tanpa ini `elk` dan `ELK` lolos validasi format tapi ditolak unique,
     * karena unique index SQLite bersifat case-sensitive sementara MySQL
     * tidak -- jadi kode yang sama bisa tersimpan di satu driver dan tidak di
     * driver lain. Menormalkan lebih awal membuat perilakunya sama di mana pun.
     *
     * String KOSONG harus jadi NULL, bukan `''`. Middleware
     * `ConvertEmptyStringsToNull` sudah mengubah isian kosong menjadi null;
     * kalau sini diubah balik jadi `''`, kategori tanpa prefix akan tersimpan
     * sebagai string kosong -- dan unique index memperlakukannya sebagai nilai.
     * Akibatnya hanya SATU kategori yang boleh tanpa prefix, sementara itu
     * keadaan yang wajar. Satu baris ini mencegah batasan yang tidak terlihat
     * sampai kategori kedua dikirim.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('sku_prefix')) {
            return;
        }

        $mentah = $this->input('sku_prefix');

        $this->merge([
            'sku_prefix' => is_string($mentah) && trim($mentah) !== ''
                ? strtoupper(trim($mentah))
                : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $categoryId = $this->route('category')?->id;

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('categories', 'name')->ignore($categoryId),
            ],

            // Prefiks SKU: opsional, huruf kapital/angka, 2-8 karakter.
            //
            // PENTING: pakai `min`/`max`, BUKAN `size:2,8`. Aturan `size`
            // berarti "tepat N karakter" -- `size:2,8` dibaca sebagai 2 dan
            // menolak semua prefix selain yang tepat 2 huruf. Kalau tidak
            // sengaja, combine `between:2,8` yang benar untuk rentang.
            //
            // Dipaksa menjadi kapital di `prepareForValidation()`, jadi aturan
            // `regex` cukup melarang huruf kecil dan tanda baca -- tidak perlu
            // case-insensitive yang bertabrakan dengan unique index.
            'sku_prefix' => [
                'nullable', 'string', 'min:2', 'max:8',
                'regex:/^[A-Z0-9]+$/',
                Rule::unique('categories', 'sku_prefix')->ignore($categoryId),
            ],

            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama kategori wajib diisi.',
            'name.unique' => 'Kategori dengan nama ini sudah ada.',
            'sku_prefix.min' => 'Prefiks SKU harus minimal 2 karakter.',
            'sku_prefix.max' => 'Prefiks SKU maksimal 8 karakter.',
            'sku_prefix.regex' => 'Prefiks SKU hanya boleh huruf kapital dan angka, mis. ELK.',
            'sku_prefix.unique' => 'Prefiks ini sudah dipakai kategori lain.',
        ];
    }
}
