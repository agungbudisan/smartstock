<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;

/**
 * Menyusun saran SKU untuk produk baru.
 *
 * Pola yang dipakai: `{prefix kategori}-{nomor urut}`, misalnya `ELK-005`.
 * Prefiksnya milik kategori (`categories.sku_prefix`), nomorurutnya dicari dari
 * SKU yang sudah ada.
 *
 * ## Nomor urut boleh dipakai kembali
 *
 * Kalau produk dihapus, nomornya BOLEH muncul lagi. Yang dijamin adalah
 * keunikan, bukan kesinambungan penomoran tanpa jeda. Alasannya: SKU yang
 * vacated bukan jejak yang perlu dijaga, sedangkan "nomor 7 menghilang
 * selamanya" hanya menghasilkan SKU bertingkat seperti `ELK-017` di kategori
 * yang isinya lima barang.
 *
 * Konsekuensinya: kalau `ELK-005` dihapus lalu dibuat lagi, SKU `ELK-005`
 * akan muncul untuk produk yang berbeda. Untuk katalog gudang ini tidak
 * masalah, karena SKU tidak pernah dicetak permanen. Untuk sistem yang
 * mencetak label fisik, ini keputusan yang perlu ditinjau ulang.
 *
 * ## Angka diambil di PHP, bukan di SQL
 *
 * `MAX(CAST(SUBSTR(sku, 5) AS UNSIGNED))` tidak bisa dipakai: MySQL memakai
 * `UNSIGNED`, SQLite memakai `INTEGER`, dan tidak ada satu string SQL yang
 * jalan di keduanya. Test suite jalan di SQLite sementara database sungguhan
 * jalan di MySQL, jadi kueri yang hanya benar di salah satunya akan lolos
 * seluruh test dan meledak di produksi. Menarik handful SKU per prefix dan
 * menghitung di PHP sedikit lebih lambat tapi selalu benar di mana pun.
 */
class SkuService
{
    /** Berapa digit nomor urutnya: `ELK-005`, bukan `ELK-5`. */
    private const LEBAR_NOMOR = 3;

    /**
     * Saran SKU untuk satu kategori, atau NULL bila kategori tidak punya
     * prefix.
     *
     * Kategori tanpa prefix itu kondisi yang sah, bukan error: pola SKU
     * bersifat opsional, dan memaksa admin mengisinya hanya supaya form bisa
     * filled akan menghasilkan tebakan yang lebih buruk daripada tidak ada
     * pola sama sekali.
     */
    public function suggest(?Category $category): ?string
    {
        if ($category === null || $category->sku_prefix === null || trim($category->sku_prefix) === '') {
            return null;
        }

        return $this->compose($category->sku_prefix, $this->nextNumber($category->sku_prefix));
    }

    /**
     * Saran SKU untuk beberapa kategori sekaligus, keyed by id.
     *
     * Dipakai form produk: begitu kategori dipilih, isinya sudah ada tanpa
     * perlu permintaan tambahan. Saran bisa basi kalau produk lain dibuat
     * bersamaan -- itu kondisi yang wajar, dan validasi unique di server yang
     * menjadi jaring pengamannya.
     *
     * @param  iterable<int, Category>  $categories
     * @return array<int, string>
     */
    public function suggestFor(iterable $categories): array
    {
        $saran = [];

        foreach ($categories as $category) {
            $nilai = $this->suggest($category);

            if ($nilai !== null) {
                $saran[(int) $category->getKey()] = $nilai;
            }
        }

        return $saran;
    }

    /**
     * Nomor berikutnya yang belum dipakai untuk prefix tertentu.
     *
     * Mengambil nomor TERTINGGI yang ada, bukan "jumlah produk + 1". Kalau
     * ada produk yang dihapus, hitungan akan meleset dan menghasilkan nomor
     * yang sudah terpakai.
     */
    public function nextNumber(string $prefix): int
    {
        $tertinggi = 0;

        foreach ($this->skusWithPrefix($prefix) as $sku) {
            if (preg_match('/^'.preg_quote($prefix, '/').'-(\d+)$/i', $sku, $cocok) === 1) {
                $tertinggi = max($tertinggi, (int) $cocok[1]);
            }
        }

        return $tertinggi + 1;
    }

    private function compose(string $prefix, int $number): string
    {
        return strtoupper($prefix).'-'.str_pad((string) $number, self::LEBAR_NOMOR, '0', STR_PAD_LEFT);
    }

    /**
     * SKU yang dimulai dengan `PREFIX-`, TERMASUK produk yang di-arsipkan.
     *
     * `withTrashed()` itu wajib, bukan sekadar rapi. Unique index di
     * `products.sku` tidak mengenal soft delete -- baris yang diarsipkan masih
     * duduk di tabel dan masih ikut terindeks. Kalau produk diarsipkan
     * diabaikan di sini, sarannya akan menawarkan SKU yang sudah terpakai dan
     * `INSERT`-nya ditolak unique constraint: user melihat saran yang terlihat
     * benar lalu mysteriously gagal disimpan.
     *
     * Konsekuensi yang dianggap benar: SKU nomor 2 yang diarsipkan TIDAK akan
     * muncul lagi untuk produk baru. Itu benar, karena "nomor vakum" di sini
     * bukan nomor kosong.
     *
     * `LIKE` dengan wildcard di kiri, jadi index tidak terpakai. Untuk katalog
     * gudang berukuran wajar itu tidak masalah; kalau nanti tumbuh jadi
     * ratusan ribu baris, langkah berikutnya adalah kolom `sku_prefix` terpisah
     * di `products` -- bukan membuat kueri lebih pintar.
     *
     * @return list<string>
     */
    private function skusWithPrefix(string $prefix): array
    {
        return Product::withTrashed()
            ->where('sku', 'like', $prefix.'-%')
            ->pluck('sku')
            ->all();
    }
}
