<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Halaman depan aplikasi.
     *
     * Sekarang halaman depan adalah redirect ke dashboard (lalu ke login bila
     * belum masuk), jadi ekspektasinya 302 — bukan lagi respons 200 seperti
     * pada skeleton Laravel awal.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('dashboard', absolute: false));
    }
}
