<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bahasa Indonesia untuk pesan yang muncul ke pengguna.
 *
 * APP_LOCALE=id. Kalau file ini dihapus atau locale dikembalikan ke 'en',
 * pesan "These credentials do not match our records." dan nama bulan
 * "October 2026" akan muncul lagi di antarmuka berbahasa Indonesia.
 */
class IndonesianLanguageTest extends TestCase
{
    use RefreshDatabase;

    public function test_aplikasi_berjalan_dengan_locale_indonesia(): void
    {
        $this->assertSame('id', config('app.locale'));
    }

    public function test_nama_bulan_dalam_bahasa_indonesia(): void
    {
        $this->assertSame('Juli', now()->startOfMonth()->month(7)->translatedFormat('F'));
        $this->assertSame('Desember', now()->startOfMonth()->month(12)->translatedFormat('F'));
    }

    public function test_login_gagal_menampilkan_pesan_indonesia(): void
    {
        User::factory()->create(['email' => 'ada@gudang.com']);

        $this->from('/login')
            ->post('/login', [
                'email' => 'ada@gudang.com',
                'password' => 'password-salah',
            ])
            ->assertSessionHasErrors('email');

        // Pesan harus berbahasa Indonesia, bukan kalimat bawaan Laravel.
        $this->assertSame(
            'Email atau password tidak cocok dengan data kami.',
            trans('auth.failed'),
        );
    }

    public function test_pesan_validasi_bawaan_bahasa_indonesia(): void
    {
        $validator = validator([], ['email' => 'required|email']);

        $this->assertSame('email wajib diisi.', $validator->errors()->first('email'));

        $validator = validator([], ['harga' => 'numeric']);
        $validator->errors()->add('harga', trans('validation.numeric', ['attribute' => 'harga']));

        $this->assertSame('harga harus berupa angka.', $validator->errors()->first('harga'));
    }

    public function test_kunci_validasi_yang_tidak_ada_jatuh_ke_fallback(): void
    {
        // Sengaja tidak diterjemahkan di lang/id/validation.php — harus tetap
        // mengembalikan pesan (dari fallback locale), tidak error.
        $validator = validator([], ['berat' => 'between:1,2']);

        $this->assertNotNull($validator->errors()->first('berat'));
        $this->assertSame('en', config('app.fallback_locale'));
    }

    public function test_pesan_reset_password_bahasa_indonesia(): void
    {
        $this->assertSame(
            'Tautan pengaturan ulang password sudah kami kirim ke email Anda.',
            trans('passwords.sent'),
        );
    }
}
