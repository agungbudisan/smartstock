<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\SkuService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Saran SKU otomatis: `{prefix kategori}-{nomor urut}`, misal `ELK-005`.
 *
 * Tiga aturan yang diuji di sini, dan ketiganya punya alasan yang berbeda:
 *
 *  1. Nomor diambil dari yang TERtingGI, bukan dari jumlah produk. Kalau ada
 *     produk dihapus, hitungan meleset dan menghasilkan nomor yang sudah dipakai.
 *  2. Nomor boleh dipakai kembali setelah produk dihapus. Yang dijamin adalah
 *     keunikan, bukan kesinambungan penomoran.
 *  3. Kategori tanpa prefix menghasilkan null, bukan tebakan. Memaksa admin
 *     mengisinya demi bisa menampilkan pola hanya menghasilkan pola palsu.
 */
class SkuSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private SkuService $sku;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sku = new SkuService;
    }

    private function kategori(string $prefix): Category
    {
        return Category::factory()->create(['sku_prefix' => $prefix]);
    }

    // -----------------------------------------------------------------
    // Skema
    // -----------------------------------------------------------------

    public function test_kategori_punya_kolom_prefix(): void
    {
        $this->assertTrue(Schema::hasColumn('categories', 'sku_prefix'));
    }

    public function test_prefix_kategori_unik(): void
    {
        $this->kategori('ELK');

        $this->expectException(QueryException::class);

        Category::factory()->create(['sku_prefix' => 'ELK']);
    }

    public function test_banyak_kategori_boleh_tanpa_prefix(): void
    {
        // NULL tidak melanggar unique index, jadi kategori tanpa prefix boleh
        // sebanyak-banyaknya. Kalau tidak, hanya satu kategori bisa kosong --
        // dan itu akan mendorong admin mengarang prefix asal-asalan.
        Category::factory()->create(['sku_prefix' => null]);
        Category::factory()->create(['sku_prefix' => null]);

        $this->assertSame(2, Category::whereNull('sku_prefix')->count());
    }

    // -----------------------------------------------------------------
    // Service
    // -----------------------------------------------------------------

    public function test_kategori_tanpa_prefix_tidak_menghasilkan_saran(): void
    {
        $tanpaPrefix = Category::factory()->create(['sku_prefix' => null]);
        $kosong = Category::factory()->create(['sku_prefix' => '   ']);

        $this->assertNull($this->sku->suggest($tanpaPrefix));
        $this->assertNull($this->sku->suggest($kosong), 'Prefix berisi spasi harus dianggap tidak ada');
        $this->assertNull($this->sku->suggest(null), 'Kategori null harus aman');
    }

    public function test_kategori_baru_mendapat_nomor_satu(): void
    {
        $this->assertSame('ELK-001', $this->sku->suggest($this->kategori('ELK')));
    }

    public function test_nomor_naik_dari_yang_tertinggi(): void
    {
        $kategori = $this->kategori('ELK');

        foreach ([1, 2, 3] as $nomor) {
            Product::factory()->create(['sku' => sprintf('ELK-%03d', $nomor)]);
        }

        $this->assertSame('ELK-004', $this->sku->suggest($kategori));
    }

    public function test_nomor_diambil_dari_yang_tertinggi_bukan_jumlah_baris(): void
    {
        // SKU dengan nomor tinggi tanpa nomor di antaranya: kalau logic-nya
        // menghitung jumlah baris, hasilnya ELK-002 yang sudah terpakai.
        $kategori = $this->kategori('ELK');

        Product::factory()->create(['sku' => 'ELK-001']);
        Product::factory()->create(['sku' => 'ELK-017']);
        Product::factory()->create(['sku' => 'ELK-020']);

        $this->assertSame('ELK-021', $this->sku->suggest($kategori));
    }

    public function test_produk_diarsipkan_masih_mereservasi_nomor_skunya(): void
    {
        $kategori = $this->kategori('ELK');

        foreach ([1, 2, 3] as $nomor) {
            Product::factory()->create(['sku' => sprintf('ELK-%03d', $nomor)]);
        }

        Product::where('sku', 'ELK-002')->first()->delete();

        $this->assertSame(
            'ELK-004',
            $this->sku->suggest($kategori),
            'SKU produk terarsipkan masih ada di tabel dan masih terindeks unique, jadi'
            .' nomornya belum kosong. Kalau diabaikan, sarannya akan menawarkan SKU'
            .' yang pasti ditolak database saat disimpan.'
        );
    }

    public function test_nomor_yang_benar_benar_kosong_dipakai_lagi(): void
    {
        $kategori = $this->kategori('ELK');

        // ELK-002 sengaja TIDAK pernah ada, jadi inilah celah yang harus
        // dipakai -- bukan melompat ke 003.
        Product::factory()->create(['sku' => 'ELK-001']);

        $this->assertSame('ELK-002', $this->sku->suggest($kategori));
    }

    public function test_sku_yang_tidak_ikut_pola_diabaikan(): void
    {
        $kategori = $this->kategori('ELK');

        // Produk yang dibuat manual dengan SKU di luar pola harus diabaikan,
        // kalau tidak satu kode acak bisa melompati penomoran.
        Product::factory()->create(['sku' => 'BAR-ABC123']);
        Product::factory()->create(['sku' => 'ELK-LEGACY']);
        Product::factory()->create(['sku' => 'ELK-004']);

        $this->assertSame('ELK-005', $this->sku->suggest($kategori));
    }

    public function test_prefix_lain_tidak_mengganggu(): void
    {
        $elektronik = $this->kategori('ELK');
        $sembako = $this->kategori('SEM');

        foreach ([1, 2, 3, 4, 5] as $nomor) {
            Product::factory()->create(['sku' => sprintf('ELK-%03d', $nomor)]);
        }

        $this->assertSame('ELK-006', $this->sku->suggest($elektronik));
        $this->assertSame('SEM-001', $this->sku->suggest($sembako), 'Penomoran antar kategori terpisah');
    }

    public function test_saran_untuk_sekalian_kategori(): void
    {
        $elektronik = $this->kategori('ELK');
        $tanpaPrefix = Category::factory()->create(['sku_prefix' => null]);

        Product::factory()->create(['sku' => 'ELK-001']);

        $saran = $this->sku->suggestFor([$elektronik, $tanpaPrefix]);

        $this->assertSame(['ELK-002'], array_values($saran));
        $this->assertArrayNotHasKey($tanpaPrefix->id, $saran);
    }

    // -----------------------------------------------------------------
    // Validasi prefix kategori
    // -----------------------------------------------------------------

    private function admin(): User
    {
        return User::factory()->adminGudang()->create();
    }

    public function test_prefix_kategori_bisa_disimpan(): void
    {
        $this->actingAs($this->admin())
            ->post('/categories', ['name' => 'Alat Berat', 'sku_prefix' => 'ALB'])
            ->assertSessionHasNoErrors();

        $this->assertSame('ALB', Category::where('name', 'Alat Berat')->value('sku_prefix'));
    }

    public function test_prefix_huruf_kecil_dinormalisasi_jadi_kapital(): void
    {
        // Tanpa normalisasi, `elk` lolos validasi format tapi ditolak unique
        // kalau ELK sudah dipakai -- dan keliakannya berbeda antar driver,
        // karena unique index SQLite case-SENSITIVE sementara MySQL tidak.
        $this->actingAs($this->admin())
            ->post('/categories', ['name' => 'Olah Rempah', 'sku_prefix' => 'orm'])
            ->assertSessionHasNoErrors();

        $this->assertSame('ORM', Category::where('name', 'Olah Rempah')->value('sku_prefix'));
    }

    /**
     * Rentang panjang prefix harus memakai `min`/`max`, bukan `size`.
     *
     * `size:2,8` berarti "tepat 2 karakter" -- Laravel membacanya sebagai 2 dan
     * menolak semua prefix yang bukan tepat 2 huruf. Bug ini tidak terlihat di
     * kode karena aturannya terlihat sangat masuk akal.
     */
    public function test_rentang_panjang_prefix_bukan_size(): void
    {
        $this->actingAs($this->admin())
            ->post('/categories', ['name' => 'Tiga Huruf', 'sku_prefix' => 'ABC'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin())
            ->post('/categories', ['name' => 'Delapan Karakter', 'sku_prefix' => 'ABCDEFGH'])
            ->assertSessionHasNoErrors();

        // Di luar rentang tetap ditolak, dengan pesan yang menyebut batasnya.
        $this->actingAs($this->admin())
            ->post('/categories', ['name' => 'Terlalu Panjang', 'sku_prefix' => 'ABCDEFGHI'])
            ->assertSessionHasErrors('sku_prefix');

        $this->actingAs($this->admin())
            ->post('/categories', ['name' => 'Terlalu Pendek', 'sku_prefix' => 'A'])
            ->assertSessionHasErrors('sku_prefix');
    }

    public function test_prefix_hanya_boleh_huruf_kapital_dan_angka(): void
    {
        $this->actingAs($this->admin())
            ->post('/categories', ['name' => 'Pakai Tanda', 'sku_prefix' => 'EL-K'])
            ->assertSessionHasErrors('sku_prefix');
    }

    public function test_prefix_yang_sudah_dipakai_ditolak(): void
    {
        $this->kategori('ELK');

        $this->actingAs($this->admin())
            ->post('/categories', ['name' => 'Kategori Baru', 'sku_prefix' => 'ELK'])
            ->assertSessionHasErrors('sku_prefix');

        $this->assertSame(1, Category::where('sku_prefix', 'ELK')->count());
    }

    public function test_kategori_tanpa_prefix_tetap_boleh_disimpan(): void
    {
        // Memaksa admin mengisinya hanya supaya form bisa menampilkan pola akan
        // menghasilkan tebakan yang lebih buruk daripada tidak ada pola.
        $this->actingAs($this->admin())
            ->post('/categories', ['name' => 'Tanpa Prefix', 'sku_prefix' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull(Category::where('name', 'Tanpa Prefix')->value('sku_prefix'));
    }

    /**
     * BEBERAPA kategori boleh tanpa prefix, bukan cuma satu.
     *
     * Ini yang paling mudah terlewat: string kosong `''` dan NULL berbeda
     *(unique index menganggap `''` sebuah nilai, NULL tidak), jadi kalau form
     * kosong disimpan sebagai `''`, hanya kategori PERTAMA yang bisa tanpa
     * prefix dan kategori kedua ditolak unique -- kesalahannya muncul jauh dari
     * penyebabnya.
     */
    public function test_banyak_kategori_tanpa_prefix_boleh_disimpan_lewat_form(): void
    {
        $admin = $this->admin();

        foreach (['Tanpa Prefix Satu', 'Tanpa Prefix Dua', 'Tanpa Prefix Tiga'] as $nama) {
            $this->actingAs($admin)
                ->post('/categories', ['name' => $nama, 'sku_prefix' => ''])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(
            3,
            Category::whereIn('name', ['Tanpa Prefix Satu', 'Tanpa Prefix Dua', 'Tanpa Prefix Tiga'])
                ->whereNull('sku_prefix')
                ->count(),
        );
    }

    public function test_prefix_kategori_tetap_valid_saat_diubah(): void
    {
        $kategori = $this->kategori('ELK');

        $this->actingAs($this->admin())
            ->patch('/categories/'.$kategori->id, ['name' => $kategori->name, 'sku_prefix' => 'ELK'])
            ->assertSessionHasNoErrors();

        $this->assertSame('ELK', $kategori->refresh()->sku_prefix);
    }

    // -----------------------------------------------------------------
    // Halaman produk
    // -----------------------------------------------------------------

    public function test_halaman_create_mengirim_saran_per_kategori(): void
    {
        $elektronik = Category::factory()->create(['sku_prefix' => 'ELK']);
        Category::factory()->create(['sku_prefix' => null]);

        Product::factory()->create(['sku' => 'ELK-001']);

        $this->actingAs(User::factory()->adminGudang()->create())
            ->get('/products/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('saranSku.'.$elektronik->id, 'ELK-002')
                ->has('categories.0.sku_prefix')
            );
    }

    public function test_halaman_edit_tidak_mengubah_sku_yang_sudah_ada(): void
    {
        // Halaman edit TIDAK BOLEH mengisi ulang field SKU. SKU yang sudah pernah
        // terbit bisa tercetak di label fisik dan sudah tercantum di PO yang
        // pernah keluar; berubah sendiri karena kategori diganti akan memutus
        // keduanya. Form menegakkan ini di sisi Vue.
        $produk = Product::factory()->create(['sku' => 'SKU-LAMA-SEKALI']);
        $kategori = Category::factory()->create(['sku_prefix' => 'ELK']);

        $this->actingAs(User::factory()->adminGudang()->create())
            ->get('/products/'.$produk->id.'/edit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('product.sku', 'SKU-LAMA-SEKALI')
                ->where('saranSku.'.$kategori->id, 'ELK-001')
            );
    }
}
