<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $productId = $this->route('product')?->id;
        $isEdit = $productId !== null;

        // Bolehkah form produk menghasilkan Inbound pembuka? Hanya Admin Gudang.
        // Manager boleh menulis master data (termasuk reorder point, yang
        // memang keputusan manajemen), tapi tidak boleh menciptakan pergerakan
        // stok. Penjelasan lengkapnya di aturan `current_stock` di bawah.
        $bolehCatatStok = (bool) $this->user()?->isAdminGudang();

        return [
            'sku' => [
                'required', 'string', 'max:50',
                // SKU unik, tapi saat edit produk itu sendiri tetap boleh.
                Rule::unique('products', 'sku')->ignore($productId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => [
                'nullable',
                Rule::exists(Category::class, 'id'),
            ],

            // `current_stock` HANYA boleh diisi saat membuat produk, dan itu
            // diperlakukan sebagai stok pembuka yang dicatat sebagai Inbound
            // (lihat ProductController::store).
            //
            // Saat edit field ini DILARANG, bukan sekadar diabaikan. Kalau
            // diabaikan, perubahan stok yang diketik pengguna hilang tanpa
            // jejak — persis bug yang pernah terjadi di sini: form produk
            // menulis `current_stock` langsung sehingga
            // `SUM(quantity) == current_stock` jadi tidak berlaku dan jejak
            // audit tidak mencatat apa pun. Stok hanya boleh berubah lewat
            // InventoryMovementService.
            //
            // Batas ketiga: untuk peran yang TIDAK berwenang mencatat stok,
            // angka selain nol DITOLAK, bukan diabaikan.
            //
            // `POST /products` terbuka untuk Manager karena master data boleh
            // ditulis keduanya. Tanpa aturan ini, Manager membuat produk yang
            // langsung mencatat Inbound atas namanya sendiri — dan jejaknya
            // berbunyi "Inbound +500 ... oleh Dewi Lestari", persis operasi
            // yang RBAC nyatakan miliknya Admin Gudang. Aturan RBAC tegak per
            // route, sedangkan yang benar-benar menentukan di sini adalah APA
            // yang dilakukan, bukan DI MANA.
            //
            // `in:0` dipakai supaya "tidak mengirim key-nya" dan "mengirim
            // angka 0" sama-sama diterima. Menolak keduanya memaksa form agar
            // tahu hal yang tidak seharusnya ia punya.
            'current_stock' => match (true) {
                $isEdit => ['prohibited'],
                $bolehCatatStok => ['required', 'integer', 'min:0'],
                default => ['nullable', 'integer', 'in:0'],
            },

            // Asal-usul stok pembuka. Opsional: stok awal dari hasil hitung
            // fisik sering tidak punya dokumen pembelian. Kalau diisi, Inbound
            // pembuka dan jejak audit akan menyebut nama pemasoknya.
            //
            // Total forbidden di luar Admin Gudang, karena mengisi kolom ini
            // berarti mencatat Inbound — persis yang dilarang di atas.
            'supplier_id' => match (true) {
                $isEdit => ['prohibited'],
                $bolehCatatStok => ['nullable', 'integer', 'exists:suppliers,id'],
                default => ['prohibited'],
            },

            'reorder_point' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sku.required' => 'SKU wajib diisi.',
            'sku.unique' => 'SKU ini sudah dipakai produk lain.',
            'name.required' => 'Nama produk wajib diisi.',
            'category_id.exists' => 'Kategori yang dipilih tidak dikenal.',
            'current_stock.required' => 'Stok awal wajib diisi.',
            'current_stock.min' => 'Stok tidak boleh negatif.',
            'current_stock.in' => 'Stok awal hanya boleh diisi oleh Admin Gudang. Catat lewat entri Inbound atau Stock Opname.',
            'current_stock.prohibited' => 'Stok tidak bisa diubah dari form produk. Catat lewat entri Inbound atau Stock Opname.',
            'supplier_id.exists' => 'Pemasok yang dipilih tidak dikenal.',
            'supplier_id.prohibited' => $this->user()?->isAdminGudang()
                ? 'Pemasok stok awal hanya bisa diatur saat produk dibuat.'
                : 'Pemasok stok awal hanya bisa diisi oleh Admin Gudang. Catat lewat entri Inbound.',
            'reorder_point.required' => 'Batas aman (reorder point) wajib diisi.',
            'reorder_point.min' => 'Batas aman tidak boleh negatif.',
        ];
    }
}
