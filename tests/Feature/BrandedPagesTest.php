<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Halaman Auth & Profil setelah dibranding dengan design token.
 *
 * Yang dijaga di sini bukan tampilan (itu urusan build Vite), tapi kontrak
 * halaman: komponen yang dirender, data yang dikirim, dan aksi yang bekerja.
 */
class BrandedPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->adminGudang()->create();
    }

    // -----------------------------------------------------------------
    // Auth
    // -----------------------------------------------------------------

    public function test_halaman_masuk_tetap_dirender_dengan_prop_yang_benar(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/Login')
                ->where('canResetPassword', true)
                ->has('status')
            );
    }

    public function test_halaman_daftar_dirender(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/Register')
            );
    }

    public function test_halaman_lupa_password_dirender(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/ForgotPassword')
            );
    }

    public function test_halaman_atur_password_baru_menerima_token_dan_email(): void
    {
        $this->get('/reset-password/token-abc?email=budi@gudang.com')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/ResetPassword')
                ->where('token', 'token-abc')
                ->where('email', 'budi@gudang.com')
            );
    }

    public function test_halaman_konfirmasi_password_dirender(): void
    {
        $this->actingAs($this->admin)
            ->get('/confirm-password')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/ConfirmPassword')
            );
    }

    public function test_login_gagal_mengirim_pesan_error_ke_prop_errors(): void
    {
        $this->post('/login', [
            'email' => 'salah@gudang.com',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        // Redirect balik ke /login, lalu prop errors harus berisi pesannya.
        $this->get('/login')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('errors')
                ->where('errors.email', fn ($message): bool => is_string($message) && $message !== '')
            );
    }

    // -----------------------------------------------------------------
    // Profil
    // -----------------------------------------------------------------

    public function test_halaman_profil_bisa_diakses_semua_peran(): void
    {
        $this->actingAs($this->admin)
            ->get('/profile')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Profile/Edit')
            );

        $this->actingAs(User::factory()->managerBisnis()->create())
            ->get('/profile')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Profile/Edit')
            );
    }

    public function test_profil_bisa_memperbarui_nama_dan_email(): void
    {
        $this->actingAs($this->admin)
            ->patch('/profile', [
                'name' => 'Budi Santoso',
                'email' => 'budi@smartstock.test',
            ])
            ->assertSessionHasNoErrors();

        $this->admin->refresh();

        $this->assertSame('Budi Santoso', $this->admin->name);
        $this->assertSame('budi@smartstock.test', $this->admin->email);
    }

    public function test_email_duplikat_ditolak_saat_perbarui_profil(): void
    {
        User::factory()->create(['email' => 'udah@ada.test']);

        $this->actingAs($this->admin)
            ->from('/profile')
            ->patch('/profile', [
                'name' => 'Ganti',
                'email' => 'udah@ada.test',
            ])
            ->assertSessionHasErrors('email');

        $this->assertNotSame('udah@ada.test', $this->admin->fresh()->email);
    }

    public function test_peran_tidak_bisa_diubah_lewat_profil(): void
    {
        $this->actingAs($this->admin)
            ->patch('/profile', [
                'name' => 'Budi',
                'email' => $this->admin->email,
                // Percobaan naikkan diri jadi manager lewat form profil.
                'role' => 'manager_bisnis',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->admin->fresh()->isAdminGudang());
    }

    public function test_hapus_akun_memerlukan_password_yang_benar(): void
    {
        $this->actingAs($this->admin)
            ->from('/profile')
            ->delete('/profile', ['password' => 'password-keliru'])
            ->assertSessionHasErrors('password');

        $this->assertNotNull(User::find($this->admin->id));
    }

    public function test_hapus_akun_dengan_password_benar(): void
    {
        $this->actingAs($this->admin)
            ->delete('/profile', ['password' => 'password']);

        $this->assertNull(User::find($this->admin->id));
    }

    public function test_profil_tidak_bisa_diakses_tanpa_login(): void
    {
        $this->get('/profile')->assertRedirect('/login');
    }
}
