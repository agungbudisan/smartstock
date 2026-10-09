<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Setiap item menu harus bisa dibuka oleh peran yang menampilkannya.
 *
 * guarding ini lahir dari keluhan nyata: menu Manager pernah menunjuk ke
 * `/inventory/movements`, halaman berisi form entri. Route-nya terbuka untuk
 * Manager, jadi halamannya termuat -- tapi `POST`-nya ditolak. Manager membuka
 * halamannya, mengisi formnya dengan benar, lalu mendapat 403 saat menekan
 * Simpan, tanpa penjelasan di mana pun.
 *
 * Menu dirender di browser, jadi tidak ada yang bisa diukurnya lewat respons
 * HTTP. Yang bisa, dan justru yang penting, adalah memeriksa bahwa SETIAP href
 * di menu benar-benar bisa dibuka oleh perannya -- supaya tidak ada menu yang
 * menjadi jebakan.
 *
 * Sumber menu dibaca dari `config/navigation.php`, bukan dari `AppLayout.vue`.
 * Sebelumnya test ini mem-parse teks JavaScript: begitu susunannya berubah --
 * bukan karena ada bug, hanya karena layout-nya pindah ke sidebar -- test gagal
 * tanpa ada yang salah di aplikasi. Membaca file PHP sebagai PHP membuat test
 * hanya gagal kalau pendekatannya memang salah.
 */
class NavigationReachableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Halaman yang boleh Dialihkan alih-alih dibuka untuk peran tertentu.
     * `DashboardController` mengarahkan tiap peran ke modulnya.
     *
     * @var array<string, array<int, string>>
     */
    private const DIALIHKAN = [
        'admin_gudang' => ['/dashboard'],
        'manager_bisnis' => ['/dashboard'],
    ];

    public function test_setiap_item_menu_bisa_dibuka_oleh_admin_gudang(): void
    {
        $this->assertMenuReachable('admin_gudang');
    }

    public function test_setiap_item_menu_bisa_dibuka_oleh_manager_bisnis(): void
    {
        $this->assertMenuReachable('manager_bisnis');
    }

    /**
     * Menu Manager TIDAK BOLEH menunjuk ke halaman form entri.
     *
     * Diperiksa terpisah karena aturan ini bisa dilanggar tanpa membuat test
     * "bisa dibuka" gagal: `/inventory/movements` pernah terbuka untuk Manager,
     * jadi pemeriksaan status 200 akan tetap hijau.
     */
    public function test_menu_manager_tidak_menunjuk_ke_halaman_form_entri(): void
    {
        $this->assertNotContains(
            '/inventory/movements',
            array_column($this->menuUntuk('manager_bisnis'), 'href'),
            'Menu Manager tidak boleh menunjuk ke halaman form entri: halaman itu '
            .'menampilkan form yang hanya bisa disimpan Admin Gudang.',
        );
    }

    public function test_menu_admin_tidak_menunjuk_ke_dasbor_manajer(): void
    {
        $this->assertNotContains(
            '/analytics',
            array_column($this->menuUntuk('admin_gudang'), 'href'),
            'Dasbor analitik hanya untuk Manager; Admin tidak punya aksesnya.',
        );
    }

    /**
     * Admin Gudang harus punya jalan masuk ke buku besar dan jejak audit.
     *
     * Halaman-halaman itu tidak ada di menu Manager, jadi tidak ada test lain
     * yang secara tidak sengaja menyentuhnya -- dan menghapusnya dari menu Admin
     * akan lolos semua pemeriksaan di atas, karena status 403 vs 200 tidak akan
     * pernah terjadi: halamannya memang masih bisa dibuka, hanya tidak bisa
     * ditemukan.
     */
    public function test_admin_gudang_masih_mendapat_buku_besar_dan_jejak_audit(): void
    {
        $href = array_column($this->menuUntuk('admin_gudang'), 'href');

        $this->assertContains('/inventory/ledger', $href, 'Admin perlu akses baca ke Buku Besar.');
        $this->assertContains('/inventory/audit-logs', $href, 'Admin perlu akses baca ke Jejak Audit.');
        $this->assertContains('/inventory/movements', $href, 'Admin harus punya halaman entri.');
    }

    /**
     * Setiap grup yang disebut di `per_role` harus benar-benar ada di `groups`.
     *
     * `HandleInertiaRequests` menyaring grup dengan `array_map` lalu
     * `array_filter` tanpa memvalidasi -- nama yang salah di `per_role` akan
     * hilang begitu saja. Tidak ada error, tidak ada grup kosong, hanya modul
     * yang tiba-tiba tidak bisa ditemukan di sidebar.
     */
    public function test_setiap_grup_yang_dirujuk_per_role_ada_di_daftar_grup(): void
    {
        $grup = array_keys(config('navigation.groups'));
        $peran = config('navigation.per_role');

        $this->assertNotEmpty($grup, 'Harus ada minimal satu grup navigasi.');
        $this->assertNotEmpty($peran, 'Setiap peran harus punya minimal satu grup.');

        foreach ($peran as $namaPeran => $urutan) {
            $this->assertNotEmpty($urutan, "Peran {$namaPeran} harus punya minimal satu grup.");

            foreach ($urutan as $key) {
                $this->assertContains(
                    $key,
                    $grup,
                    "Peran {$namaPeran} merujuk grup '{$key}' yang tidak ada di navigation.groups. "
                    .'Grup itu akan hilang dari sidebar tanpa error.',
                );
            }
        }
    }

    /**
     * Setiap peran harus punya setidaknya satu item menu.
     *
     * Bug yang sama seperti di atas, tapi lebih sunyap: `per_role` yang merujuk
     * grup sah yang semua item-nya ter-saring oleh `roles` akan menghasilkan
     * sidebar kosong. Tidak ada error, halaman tetap terbuka, dan tidak ada lagi
     * cara mengubah modul tanpa mengetik URL.
     */
    public function test_tidak_ada_peran_dengan_menu_kosong(): void
    {
        foreach (array_keys(config('navigation.per_role')) as $peran) {
            $this->assertNotEmpty(
                $this->menuUntuk($peran),
                "Menu peran {$peran} kosong setelah penyaringan `roles`. "
                .'Sidebar akan tampil tanpa satu pun item.',
            );
        }
    }

    /**
     * Group heading hanya boleh muncul sekali per peran.
     *
     * Dua grup dengan `heading` yang sama menghasilkan dua blok "ANALITIK"
     * bersebelahan di sidebar -- bagian yang justru ditambahkan untuk membuat
     * navigasi lebih terbaca, jadi pengulangan seperti ini menghapus
     * keuntungannya tanpa memberi apa pun.
     */
    public function test_judul_grup_tidak_berulang_dalam_satu_peran(): void
    {
        foreach (array_keys(config('navigation.per_role')) as $peran) {
            // Dibaca per GRUP, bukan per item. Kalau diambil dari daftar item,
            // setiap judul otomatis muncul sebanyak jumlah item di grupnya --
            // test itu akan selalu gagal, atau (kalau di-`array_unique` dulu)
            // tidak akan pernah bisa menangkap pengulangan yang sebenarnya.
            $judul = array_values(array_filter(array_map(
                fn (string $key): ?string => config('navigation.groups.'.$key.'.heading'),
                config('navigation.per_role.'.$peran, []),
            )));

            $berulang = array_keys(array_filter(
                array_count_values($judul),
                fn (int $n): bool => $n > 1,
            ));

            $this->assertSame([], $berulang, "Judul grup berulang pada peran {$peran}.");
        }
    }

    /**
     * Setiap item harus punya label dan href yang bisa dipakai.
     *
     * Item tanpa `href` akan dirender sebagai tautan mati -- Inertia
     * memperlakukan `<Link href="">` sebagai klik yang tidak melakukan apa pun.
     */
    public function test_setiap_item_memiliki_label_dan_href(): void
    {
        foreach (array_keys(config('navigation.per_role')) as $peran) {
            foreach ($this->menuUntuk($peran) as $item) {
                $this->assertNotEmpty(
                    $item['href'] ?? null,
                    "Item menu untuk peran {$peran} tidak punya href: ".json_encode($item),
                );
                $this->assertNotEmpty(
                    $item['label'] ?? null,
                    "Item menu {$item['href']} tidak punya label.",
                );
                $this->assertNotEmpty(
                    $item['icon'] ?? null,
                    "Item menu {$item['href']} tidak punya icon; SidebarNav akan "
                    .'jatuh ke ikon kotak karena tidak tahu bentuk yang dimaksud.',
                );
            }
        }
    }

    /**
     * Sidebar hanya boleh mengirim grup yang benar-benar punya isi.
     *
     * Kalau sebuah grup menyaring habis, ia akan tampil sebagai judul sendirian
     * di sidebar dengan tidak ada apa pun di bawahnya.
     */
    public function test_tidak_ada_grup_kosoh_di_sidebar(): void
    {
        foreach (['admin_gudang', 'manager_bisnis'] as $peran) {
            foreach (config('navigation.per_role.'.$peran, []) as $key) {
                $grup = config('navigation.groups.'.$key);
                $sisa = array_filter(
                    $grup['items'] ?? [],
                    fn (array $item): bool => ($item['roles'] ?? null) === null
                        || in_array($peran, $item['roles'], true),
                );

                $this->assertNotEmpty(
                    $sisa,
                    "Grup '{$key}' habis tersaring untuk peran {$peran}; judulnya akan "
                    .'muncul tanpa item di bawahnya.',
                );
            }
        }
    }

    // -----------------------------------------------------------------
    // Pemeriksaan
    // -----------------------------------------------------------------

    private function assertMenuReachable(string $role): void
    {
        $menu = $this->menuUntuk($role);
        $user = $role === 'admin_gudang'
            ? User::factory()->adminGudang()->create()
            : User::factory()->managerBisnis()->create();

        $this->assertNotEmpty($menu, "Menu untuk {$role} kosong.");

        foreach ($menu as $item) {
            $respons = $this->actingAs($user)->get($item['href']);
            $bolehDialihkan = in_array($item['href'], self::DIALIHKAN[$role], true);

            $this->assertContains(
                $respons->getStatusCode(),
                $bolehDialihkan ? [200, 302] : [200],
                sprintf(
                    'Menu %s: "%s" (%s) -> %d. Setiap item menu harus bisa dibuka '
                    .'oleh perannya; kalau tidak, pengguna menekan menu dan mendapat '
                    .'halaman yang tidak bisa ia pakai, tanpa penjelasan kenapa.',
                    $role,
                    $item['label'],
                    $item['href'],
                    $respons->getStatusCode(),
                ),
            );
        }
    }

    /**
     * Daftar menu untuk sebuah peran, dibaca dari config.
     *
     * Meniru `HandleInertiaRequests::navigation()` persis, termasuk penyaringan
     * `roles` per item. Kalau test ini tidak menyaring, ia akan memeriksa halaman
     * Admin-only seolah-olah itu halaman Manager -- dan melaporkan 403 sebagai
     * bug, padahal 403 itu jawaban yang benar. Test harus menguji apa yang
     * benar-benar dirender, bukan apa yang ada mentah di config.
     *
     * @return array<int, array{label: string, href: string, icon: string, heading: string|null}>
     */
    private function menuUntuk(string $peran): array
    {
        $grup = config('navigation.groups');
        $urutan = config('navigation.per_role.'.$peran, []);

        $hasil = [];

        foreach ($urutan as $key) {
            foreach ($grup[$key]['items'] ?? [] as $item) {
                if (($item['roles'] ?? null) !== null && ! in_array($peran, $item['roles'], true)) {
                    continue;
                }

                $hasil[] = $item + ['heading' => $grup[$key]['heading'] ?? null];
            }
        }

        return $hasil;
    }
}
