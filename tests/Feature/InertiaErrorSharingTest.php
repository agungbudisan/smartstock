<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Prop `errors` yang dibagikan HandleInertiaRequests.
 *
 * Regresi: `share()` pernah memasang `$request->errors` (ViewErrorBag) sebagai
 * prop Inertia. ViewErrorBag tidak diserialisasi menjadi objek {field: pesan},
 * sehingga prop selalu `null`, `useForm().errors` kosong, dan pesan validasi
 * tidak pernah tampil di layar, padahal session tetap membawa error.
 */
class InertiaErrorSharingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->adminGudang()->create();
    }

    public function test_halaman_tanpa_error_mengirim_prop_errors_kosong_bukan_null(): void
    {
        Product::factory()->create();

        $this->actingAs($this->admin)
            ->get('/products')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Products/Index')
                ->has('errors')
            );
    }

    public function test_error_validasi_tersampai_ke_prop_errors_setelah_redirect(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin)
            ->from("/products/{$product->id}/suppliers")
            ->post("/products/{$product->id}/suppliers", ['supplier_id' => ''])
            ->assertRedirect("/products/{$product->id}/suppliers")
            ->assertSessionHasErrors('supplier_id');

        // Redirect tadi menaruh error di session. Halaman tujuan harus
        // membacanya kembali ke prop `errors`.
        $this->actingAs($this->admin)
            ->get("/products/{$product->id}/suppliers")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Products/Suppliers')
                ->where('errors.supplier_id', 'Pilih pemasok terlebih dahulu.')
            );
    }

    public function test_error_validasi_bentuk_array_bukan_pesan_kedua(): void
    {
        $product = Product::factory()->create();

        // suppliers.*.last_purchase_price punya dua aturan (numeric + min:0).
        // useForm() hanya perlu pesan pertama per field.
        $this->actingAs($this->admin)
            ->from("/products/{$product->id}/suppliers")
            ->put("/products/{$product->id}/suppliers", [
                'suppliers' => [
                    ['supplier_id' => Supplier::factory()->create()->id, 'last_purchase_price' => 'abc'],
                ],
            ])
            ->assertSessionHasErrors('suppliers.0.last_purchase_price');

        // Kunci error mengandung titik ('suppliers.0.last_purchase_price'),
        // jadi tidak bisa dibaca lewat dot notation Inertia.
        $this->actingAs($this->admin)
            ->get("/products/{$product->id}/suppliers")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('errors', fn (mixed $errors): bool => collect($errors)
                    ->get('suppliers.0.last_purchase_price') === 'Harga beli harus berupa angka.')
            );
    }
}
