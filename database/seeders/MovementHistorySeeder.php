<?php

namespace Database\Seeders;

use App\Enums\MovementType;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryMovementService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Riwayat operasional: Inbound, Outbound, dan Stock Opname.
 *
 * SEMUA perubahan stok dibuat lewat InventoryMovementService, bukan lewat
 * `update()` langsung. Konsekuensinya:
 *
 *  - `inventory_movements` terisi dan `SUM(quantity) == products.current_stock`
 *  - `audit_logs` terisi otomatis untuk tiap pergerakan
 *  - harga beli di pivot `product_supplier` ikut ter-refresh saat Inbound
 *    menyertakan harga (jalur yang sama dengan pemakaian nyata aplikasi)
 *  - notifikasi stok menipis terkirim ke pengguna yang berhak, dengan cooldown
 *    24 jam per produk sehingga satu masalah = satu notifikasi
 *
 * Waktu disimulasikan lewat `CarbonImmutable::setTestNow()` supaya tanggal
 * movement, tanggal notifikasi, dan jendela cooldown semuanya realistis.
 *
 * ## Kenapa memakai `recordDocument()`, bukan `recordInbound()` per produk
 *
 * Seeder lama memanggil `recordInbound()` satu produk sekali. Tunnel itu benar,
 * tapi kalau dipakai per produk setiap pemanggilan membuat DOKUMEN sendiri.
 * Akibatnya satu purchase order yang sebenarnya menutup sepuluh SKU muncul
 * sebagai sepuluh dokumen berbeda dengan nomor yang sama — dan tidak ada satu
 * pun yang bisa disusun menjadi satu faktur. Rasio 1,00 baris per dokumen,
 * jadi keuntungan multi-baris tidak pernah terlihat sama sekali.
 *
 * Sekarang keputusannya dibalik: tentukan dulu seluruh gerakan satu hari,
 * lalu KELOMPOKKAN menurut siapa pemilik fakturnya, baru catat sekali per
 * kelompok.
 *
 *  - Barang masuk dikelompokkan per PEMASOK. Satu nomor PO milik satu pemasok;
 *    dua pemasok berarti dua PO, bukan satu PO yang dipecah dua dokumen.
 *  - Barang keluar dikelompokkan per OPERATOR, karena satu nota pengiriman
 *    adalah satu kirian, bukan satu produk. Ini juga yang membuat kolom
 *    `user_id` di dokumen berarti: pencatat satu kirian, bukan pencatat per
 *    baris. Kalau nanti kolom itu dibuang di tahap 4, tidak ada informasi
 *    pencatat per baris yang hilang - memang tidak pernah ada yang bisa
 *    ditafsirkan.
 *  - Stock opname jadi SATU dokumen per hari. Lembar hitung fisik memang
 *    satu lembar untuk beberapa SKU; sebelumnya `OPNAME-{tanggal}` yang sama
 *    muncul sebagai beberapa dokumen terpisah.
 *
 * Waktu transaksi dihitung per DOKUMEN, bukan per baris. Semua baris dalam satu
 * faktur memang bertanggal sama - itu definisi satu faktur. Yang tetap diacak
 * adalah jam kerja per dokumen lewat `catatPada()`.
 */
class MovementHistorySeeder extends Seeder
{
    /** Panjang riwayat, dalam hari. */
    public const HISTORY_DAYS = 120;

    /** Pengguna yang bergantian mencatat pergerakan. */
    private const OPERATORS = ['admin', 'admin_operators'];

    /** @var array<int, int> hari terakhir restok, per product_id */
    private array $lastRestock = [];

    public function __construct(private readonly MasterDataSeeder $master) {}

    public function run(): void
    {
        $service = app(InventoryMovementService::class);

        /** @var Collection<int, Product> $produk */
        $produk = $this->master->products->filter(
            fn (Product $product): bool => isset($this->master->demandProfiles[$product->id])
        );

        $awal = CarbonImmutable::yesterday()->subDays(self::HISTORY_DAYS - 1)->startOfDay();

        // Benih acak dari tanggal mulai: seeding bisa diulang dan hasilnya
        // tetap konsisten, tapi tetap berbeda dari seed sebelumnya.
        mt_srand((int) $awal->format('Ymd'));

        // Dua penghitung: baris (pemakaian lama, tetap sebanding) dan dokumen
        // (hal baru yang tahap 5 demonstrate).
        $jumlah = ['inbound' => 0, 'outbound' => 0, 'opname' => 0];
        $dokumen = ['inbound' => 0, 'outbound' => 0, 'opname' => 0];

        try {
            for ($hari = 0; $hari < self::HISTORY_DAYS; $hari++) {
                $tanggal = $awal->addDays($hari);

                // Jangkar awal hari. Tiap pemanggilan catatPada() menimpanya ke
                // waktu pencatatan dokumen masing-masing, jadi `created_at`
                // menyebar mengikuti `movement_date`. Kalau jangkar ini tidak
                // ditimpa, semua dokumen hari itu mendapat cap yang identik.
                CarbonImmutable::setTestNow($tanggal->setTime(8, 0));

                // Refresh sekali per hari, di awal. Setelah itu angka stok di
                // instance dikelola sendiri oleh fase rencana — memanggil
                // refresh() di tengah fase itu akan membuang hasil perhitungan
                // yang belum sempat ditulis ke database.
                $produk->each(fn (Product $product) => $product->refresh());

                $rencana = $hari === 0
                    ? $this->rencanaPembuka($produk)
                    : $this->rencanaHari($produk, $hari, $tanggal);

                $this->catatRencana($service, $rencana, $tanggal, '', $jumlah, $dokumen);
            }

            // Produk tanpa profil = SKU baru: stoknya diisi di sini, bukan di loop
            // utama (yang hanya memproses produk berprofil).
            //
            // Keduanya dihitung lewat referensi, jadi TIDAK perlu di-assign
            // ulang. `$jumlah = $dokumen = ...` dulu dipakai di sini dan itu
            // bug: method-nya hanya mengembalikan $jumlah, sehingga $dokumen
            // ikut tertimpa dengan hitungan BARIS. Laporannya jadi "930 baris
            // dalam 930 dokumen" padahal database-nya 287 dokumen — angka yang
            // salah persis di tempat yang paling credibility-relevant.
            $this->stokkanProdukBaru(
                $service,
                $this->master->products,
                $awal->addDays(self::HISTORY_DAYS),
                $jumlah,
                $dokumen,
            );

            $this->finalisasiStok(
                $service,
                $produk,
                $awal->addDays(self::HISTORY_DAYS),
                $jumlah,
                $dokumen,
            );
        } finally {
            // WAJIB: kalau tidak, waktu aplikasi ikut terkunci di masa lalu.
            CarbonImmutable::setTestNow();
        }

        $this->rapikanNotifikasi();

        $totalBaris = array_sum($jumlah);
        $totalDokumen = array_sum($dokumen);

        $this->command?->info(sprintf(
            '  Riwayat: %d baris (inbound %d, outbound %d, opname %d) dalam %d dokumen — %.2f baris per dokumen',
            $totalBaris,
            $jumlah['inbound'],
            $jumlah['outbound'],
            $jumlah['opname'],
            $totalDokumen,
            $totalBaris / max(1, $totalDokumen),
        ));
    }

    // -----------------------------------------------------------------
    // Fase 1: memutuskan, tanpa menulis ke database
    // -----------------------------------------------------------------

    /**
     * Rencana satu hari: tiga kelompok gerakan, masing-masing sudah dikelompokkan
     * menurut siapa pemilik fakturnya.
     *
     * @return array{keluar: array<int, array{user: User, lines: array<int, array<string, mixed>>}>,
     *               masuk: array<int, array{supplierId: int|null, user: User, lines: array<int, array<string, mixed>>}>,
     *               opname: array{user: User, lines: array<int, array<string, mixed>>}|null}
     */
    private function rencanaKosong(): array
    {
        // `opname` sengaja null, bukan array kosong.
        //
        // Jebakannya halus: array kosong itu BUKAN null, jadi `isset()` dan
        // `??=` sama-sama mengira grup sudah ada lalu melepaskannya. Akibatnya
        // kunci `user` tidak pernah dibuat, dan `catatRencana()` meledak dengan
        // "Undefined array key" tiga puluh hari kemudian — jauh dari baris yang
        // sebenarnya salah. Null satu-satunya nilai yang jujur berarti
        // "belum ada lembar hitung".
        return ['keluar' => [], 'masuk' => [], 'opname' => null];
    }

    /**
     * Stok pembuka: satu penerimaan besar di awal periode untuk setiap produk.
     *
     * Dikelompokkan per pemasok seperti PO biasa, jadi hari pertama pun sudah
     * menghasilkan beberapa dokumen satu-baris-banyak, bukan satu dokumen per
     * produk.
     *
     * @param  Collection<int, Product>  $produk
     */
    private function rencanaPembuka(Collection $produk): array
    {
        $rencana = $this->rencanaKosong();

        foreach ($produk as $product) {
            $this->lastRestock[$product->id] = 0;

            $profil = $this->master->demandProfiles[$product->id];

            $this->tambahMasuk(
                $rencana,
                $product,
                (int) $profil['opening_stock'],
                $this->pemasokTerbaik($product),
            );

            $product->current_stock = (int) $profil['opening_stock'];
        }

        return $rencana;
    }

    /**
     * Rencana satu hari kerja biasa.
     *
     * URUTAN TIGA FASI INI BERSARU-SARU dengan urutan pencatatan, dan itu wajib.
     * Keputusan restok membaca stok SETELAH barang keluar, persis seperti
     * sebelumnya: kalau barang keluar dicatat lebih dulu, produk bisa terlihat
     * masih aman padahal hari itu sudah habis terjual.
     *
     * @param  Collection<int, Product>  $produk
     */
    private function rencanaHari(Collection $produk, int $hari, CarbonImmutable $tanggal): array
    {
        $rencana = $this->rencanaKosong();

        // Fase 1: barang keluar. Stok di instance langsung dikurangi supaya
        // keputusan restok di bawah membaca angka pasca-pengiriman.
        foreach ($produk as $product) {
            $profil = $this->master->demandProfiles[$product->id];

            if (! $this->adaOutbound($profil['active_rate'])) {
                continue;
            }

            $jumlah = $this->permintaanKeluar($product, $profil, $hari, $tanggal);

            if ($jumlah <= 0) {
                continue;
            }

            $this->tambahKeluar($rencana, $product, $jumlah);

            $product->current_stock = (int) $product->current_stock - $jumlah;
        }

        // Fase 2: barang masuk.
        foreach ($produk as $product) {
            $profil = $this->master->demandProfiles[$product->id];

            if (! $this->butuhRestok($product, $profil, $hari)) {
                continue;
            }

            $jumlah = $this->kebutuhanRestok($product, $profil);

            if ($jumlah <= 0) {
                continue;
            }

            $this->tambahMasuk($rencana, $product, $jumlah, $this->pemasokTerbaik($product));

            $product->current_stock = (int) $product->current_stock + $jumlah;
            $this->lastRestock[$product->id] = $hari;
        }

        // Fase 3: stock opname sesekali, satu lembar untuk beberapa SKU.
        if ($hari % 40 === 30) {
            $this->rencanaOpname($rencana, $produk);
        }

        return $rencana;
    }

    /**
     * Barang keluar dikelompokkan per operator.
     *
     * @param  array<string, mixed>  $rencana
     */
    private function tambahKeluar(array &$rencana, Product $product, int $jumlah): void
    {
        // Operator dipilih saat kelompok pertama dibuat, jadi semua baris dalam
        // satu nota pengiriman tercatat oleh orang yang sama.
        $kunci = $this->operator()->id;

        $rencana['keluar'][$kunci] ??= ['user' => $this->operator(), 'lines' => []];

        $rencana['keluar'][$kunci]['lines'][] = ['product' => $product, 'quantity' => $jumlah];
    }

    /**
     * Barang masuk dikelompokkan per pemasok.
     *
     * Kunci 0 berarti "tanpa pemasok". Itu kondisi nyata — stok pembuka sering
     * memang tidak punya dokumen pembelian — dan mengolompokkannya sebagai satu
     * kelompok sendiri lebih jujur daripada memaksakannya masuk ke PO orang lain.
     *
     * @param  array<string, mixed>  $rencana
     */
    private function tambahMasuk(array &$rencana, Product $product, int $jumlah, ?Supplier $pemasok): void
    {
        $kunci = $pemasok?->id ?? 0;

        $rencana['masuk'][$kunci] ??= [
            'supplierId' => $pemasok?->id,
            'user' => $this->operator(),
            'lines' => [],
        ];

        $baris = ['product' => $product, 'quantity' => $jumlah];

        if ($pemasok !== null) {
            $hargaDasar = (float) ($pemasok->pivot->last_purchase_price ?? 10000);

            // Harga beli sedikit bergeser antar restok, seperti harga pasar.
            $baris['unit_price'] = round($hargaDasar * (1 + $this->acak(-0.04, 0.09)), 2);
        }

        $rencana['masuk'][$kunci]['lines'][] = $baris;
    }

    /**
     * Satu lembar opname untuk beberapa SKU, bukan satu dokumen per produk.
     *
     * @param  array<string, mixed>  $rencana
     * @param  Collection<int, Product>  $produk
     */
    private function rencanaOpname(array &$rencana, Collection $produk): void
    {
        foreach ($produk->random(min(3, $produk->count())) as $product) {
            if ($product->current_stock <= 2) {
                continue;
            }

            // Sebagian besar siklus selisihnya nol: fisik cocok dengan sistem.
            $selisih = mt_rand() / mt_getrandmax() < 0.4 ? 0 : (int) $this->acak(-5, 5);
            $fisik = max(0, (int) $product->current_stock + $selisih);

            // `rencanaKosong()` mengisi `opname` dengan NULL, jadi `??=` aman di sini:
            // hanya menulis kalau grup memang belum ada.
            $rencana['opname'] ??= ['user' => $this->operator(), 'lines' => []];

            $rencana['opname']['lines'][] = ['product' => $product, 'physical_count' => $fisik];

            $product->current_stock = $fisik;
        }
    }

    // -----------------------------------------------------------------
    // Fase 2: mencatat rencana sebagai dokumen
    // -----------------------------------------------------------------

    /**
     * Catat satu rencana sebagai beberapa dokumen, satu per kelompok.
     *
     * Urutan pencatatan (keluar, masuk, opname) WAJIB sama dengan urutan
     * penyusunan di fase rencana. Kalau tidak, dokumen barang keluar bisa
     * dihitung terhadap stok yang belum menerima kiriman hari itu - dan karena
     * dokumen bersifat utuh, satu baris gagal berarti seluruh kirian batal.
     *
     * @param  array<string, mixed>  $rencana
     * @param  array<string, int>  $jumlah
     * @param  array<string, int>  $dokumen
     */
    private function catatRencana(
        InventoryMovementService $service,
        array $rencana,
        CarbonImmutable $tanggal,
        string $sufiks,
        array &$jumlah,
        array &$dokumen,
    ): void {
        $urut = 0;

        foreach ($rencana['keluar'] as $grup) {
            $service->recordDocument(
                type: MovementType::Outbound,
                lines: $grup['lines'],
                user: $grup['user'],
                physicalReference: $this->nomor('SO', $tanggal, $sufiks, ++$urut),
                // Satu kali per DOKUMEN: semua baris dalam satu nota memang
                // bertanggal sama. Mengacak waktu per baris justru membuat satu
                // dokumen terlihat seperti kumpulan entri terpisah.
                transactionDate: $this->catatPada($tanggal, 'outbound'),
            );

            $jumlah['outbound'] += count($grup['lines']);
            $dokumen['outbound']++;
        }

        $urut = 0;

        foreach ($rencana['masuk'] as $grup) {
            $service->recordDocument(
                type: MovementType::Inbound,
                lines: $grup['lines'],
                user: $grup['user'],
                supplierId: $grup['supplierId'],
                physicalReference: $this->nomor('PO', $tanggal, $sufiks, ++$urut),
                transactionDate: $this->catatPada($tanggal, 'inbound'),
            );

            $jumlah['inbound'] += count($grup['lines']);
            $dokumen['inbound']++;
        }

        if (($rencana['opname']['lines'] ?? []) !== []) {
            $service->recordDocument(
                type: MovementType::Adjustment,
                lines: $rencana['opname']['lines'],
                user: $rencana['opname']['user'],
                physicalReference: $this->nomor('OPNAME', $tanggal, $sufiks, 1),
                transactionDate: $this->catatPada($tanggal, 'opname'),
            );

            $jumlah['opname'] += count($rencana['opname']['lines']);
            $dokumen['opname']++;
        }
    }

    /**
     * Nomor dokumen yang dibaca manusia.
     *
     * Urutan kelompok menambah akhiran hanya kalau memang ada lebih dari satu,
     * supaya `PO-20260610-001` tetap menjadi bentuk paling umum dan tidak
     * selalu trailed angka yang tidak berarti apa pun.
     */
    private function nomor(string $jenis, CarbonImmutable $tanggal, string $sufiks, int $urut): string
    {
        $bagian = array_filter([
            $jenis,
            $tanggal->format('Ymd'),
            $sufiks,
            $urut > 1 ? (string) $urut : null,
        ]);

        return implode('-', $bagian);
    }

    /**
     * @param  array<string, int>  $jumlah
     * @param  array<string, int>  $dokumen
     */
    private function stokkanProdukBaru(
        InventoryMovementService $service,
        Collection $produk,
        CarbonImmutable $tanggal,
        array &$jumlah,
        array &$dokumen,
    ): void {
        $baru = $produk->filter(
            fn (Product $product): bool => ! isset($this->master->demandProfiles[$product->id])
        );

        if ($baru->isEmpty()) {
            return;
        }

        $rencana = $this->rencanaKosong();
        $tanggalTerima = $tanggal->subDays(6);

        CarbonImmutable::setTestNow($tanggalTerima->setTime(8, 0));

        foreach ($baru as $product) {
            $product->refresh();

            $this->tambahMasuk(
                $rencana,
                $product,
                $product->reorder_point * 3,
                $this->pemasokTerbaik($product),
            );
        }

        $this->catatRencana($service, $rencana, $tanggalTerima, 'BARU', $jumlah, $dokumen);
    }

    // -----------------------------------------------------------------
    // Kondisi akhir yang disengaja
    // -----------------------------------------------------------------

    /**
     * Setelah riwayat panjang selesai, ditata agar katalog demonstrasi punya
     * keempat kondisi stok: sehat, di batas aman, di bawah batas aman, dan
     * habis.
     *
     * Semua lewat service, jadi audit trail tetap lengkap. Misi ini bukan
     * memanipulasi angka, melainkan memastikan tiap kartu di UI Reorder punya
     * contoh nyata untuk setiap kondisinya.
     *
     * Semuanya dikumpulkan ke SATU rencana dulu, lalu dicatat sebagai dokumen
     * yang dikelompokkan. Versi lama menjalankan tiap produk langsung, sehingga
     * `PO-{tanggal}-TOPUP` yang sama muncul sebagai lima dokumen terpisah —
     * padahal itu satu kali restock untuk lima SKU.
     *
     * @param  array<string, int>  $jumlah
     * @param  array<string, int>  $dokumen
     */
    private function finalisasiStok(
        InventoryMovementService $service,
        Collection $produk,
        CarbonImmutable $tanggal,
        array &$jumlah,
        array &$dokumen,
    ): void {
        CarbonImmutable::setTestNow($tanggal->setTime(8, 0));

        /**
         * @var array<int, array{0: string, 1: string}>
         */
        $peta = [
            // Sehat: ditop-up lewat penerimaan barang.
            ['ELK-001', 'sehat'],
            ['SEM-001', 'sehat'],
            ['SEM-002', 'sehat'],
            ['KEB-001', 'sehat'],
            ['ATS-002', 'sehat'],

            // Ditarik ke batas aman (atau sedikit di bawahnya, kalau loop
            // riwayat sudah meninggalkan produk di bawah batas).
            ['ELK-003', 'batas'],

            // Jauh di bawah batas aman: memicu peringatan stok menipis.
            ['ELK-002', 'tipis'],
            ['KEB-002', 'tipis'],

            // Habis total: ditemukan nol saat opname akhir.
            ['ELK-004', 'habis'],
        ];

        $rencana = $this->rencanaKosong();

        foreach ($peta as [$sku, $kondisi]) {
            $product = $produk->firstWhere('sku', $sku);

            if ($product === null) {
                continue;
            }

            $product->refresh();

            match ($kondisi) {
                'sehat' => $this->rencanaTopUp($rencana, $product),
                'batas' => $this->rencanaTurunkanKe($rencana, $product, 0.0),
                'tipis' => $this->rencanaTurunkanKe($rencana, $product, 0.35),
                'habis' => $this->rencanaOpnameNol($rencana, $product),
            };
        }

        $this->catatRencana($service, $rencana, $tanggal, 'FINAL', $jumlah, $dokumen);
    }

    /** Terima barang cukup sampai stok menjadi 4× batas aman. */
    private function rencanaTopUp(array &$rencana, Product $product): void
    {
        $kurang = ($product->reorder_point * 4) - (int) $product->current_stock;

        if ($kurang <= 0) {
            return;
        }

        $this->tambahMasuk($rencana, $product, $kurang, $this->pemasokTerbaik($product));

        $product->current_stock = (int) $product->current_stock + $kurang;
    }

    /**
     * Turunkan stok hingga berada `rasio × reorder_point` DI BAWAH batas aman.
     *
     * `$rasio` adalah jarak dari batas aman, jadi:
     *   0.00 → tepat di batas aman (kartu "perlu reorder" biasa)
     *   0.35 → 35% di bawah batas aman (memicu peringatan stok menipis)
     *
     * Dipakai outbound, bukan mengetik angka stok, supaya batas benar-benar
     * ditembus dan peringatan stok menipis ikut terpicu.
     */
    private function rencanaTurunkanKe(array &$rencana, Product $product, float $rasio): void
    {
        if ($product->current_stock <= 0) {
            return;
        }

        $target = (int) round($product->reorder_point * (1 - $rasio));
        $keluar = (int) $product->current_stock - $target;

        if ($keluar <= 0) {
            return;
        }

        $this->tambahKeluar($rencana, $product, $keluar);

        $product->current_stock = $target;
    }

    /** Stock opname menemukan nol di rak — contoh kasus "stok habis". */
    private function rencanaOpnameNol(array &$rencana, Product $product): void
    {
        $rencana['opname'] ??= ['user' => $this->operator(), 'lines' => []];

        $rencana['opname']['lines'][] = ['product' => $product, 'physical_count' => 0];

        $product->current_stock = 0;
    }

    // -----------------------------------------------------------------
    // Waktu
    // -----------------------------------------------------------------

    /**
     * Waktu transaksi yang masuk akal untuk sebuah hari kerja.
     *
     * `$tanggal` dari loop utama selalu tepat tengah malam, karena `$awal`
     * dimulai dari `startOfDay()` lalu hanya `addDays()`. Dulu nilai itu
     * diteruskan apa adanya sebagai `movement_date`, dan hasilnya 886 dari
     * 887 baris tercatat tepat pukul 00:00:00 — kolom waktu di Buku Besar
     * terlihat rusak padahal tidak ada bug di sana. `setTestNow()` di
     * run() hanya memengaruhi `created_at`, bukan `movement_date`.
     *
     * Distribusi mengikuti ritme gudang: barang datang pagi, barang keluar
     * menyebar sepanjang jam kerja. `mt_srand()` di run() membuat hasilnya
     * tetap deterministik antar-jalankan.
     */
    private function waktuKerja(CarbonImmutable $tanggal, string $jenis): CarbonImmutable
    {
        [$jamAwal, $jamAkhir] = match ($jenis) {
            'inbound' => [8, 11],
            'outbound' => [8, 16],
            default => [10, 12],
        };

        return $tanggal->setTime(mt_rand($jamAwal, $jamAkhir), mt_rand(0, 59));
    }

    /**
     * Tentukan waktu transaksi sekaligus waktu pencatatannya.
     *
     * `created_at` harus mengikuti `movement_date`, kalau tidak kolom waktu di
     * Jejak Audit akan terlihat palsu: dengan `setTestNow()` yang dipatok per
     * hari, semua entri hari itu mendapat cap yang IDENTIK (dulu 09:30, lalu
     * 17:00 — 911 dari 915 baris). Buku Besar menampilkan 08:21, 10:37,
     * 15:20 sementara Jejak Audit menampilkan 17:00 untuk ketiganya, dan itu
     * terlihat seperti bug padahal tidak.
     *
     * Jeda 1-25 menit setelah waktu transaksi meniru orang yang mencatat
     * barang beberapa saat setelah kejadiannya, dan menjamin `created_at`
     * selalu SESUDAH `movement_date` — barang tidak bisa dicatat sebelum
     * terjadi.
     *
     * Dipakai sekali per DOKUMEN di `catatRencana()`. Aman dipanggil dari
     * dalam ekspresi argumen karena efek sampingnya pada
     * `CarbonImmutable::now()` justru yang dibutuhkan.
     */
    private function catatPada(CarbonImmutable $tanggal, string $jenis): CarbonImmutable
    {
        $waktu = $this->waktuKerja($tanggal, $jenis);

        CarbonImmutable::setTestNow($waktu->addMinutes(mt_rand(1, 25)));

        return $waktu;
    }

    // -----------------------------------------------------------------
    // Model permintaan
    // -----------------------------------------------------------------

    /**
     * Permintaan harian = baseline × tren linear × musiman.
     *
     * Tren linear membuat regresi (dan prediksinya) punya arah yang jelas.
     * Musiman memakai sinus yearly supaya ada pola naik-turun yang wajar,
     * bukan noise murni.
     */
    private function permintaanHari(array $profil, int $hari, CarbonImmutable $tanggal): float
    {
        $progresi = $hari / max(1, self::HISTORY_DAYS - 1);
        $tren = 1 + ($profil['trend'] * ($progresi - 0.5) * 2);
        $musiman = 1 + 0.22 * sin(2 * M_PI * ($tanggal->dayOfYear / 365));

        return max(0.0, $profil['baseline'] * $tren * $musiman);
    }

    /**
     * Berapa yang benar-benar dikirim hari ini.
     *
     * Tidak pernah melebihi stok tersedia — gudang sungguhan begitu. Zahir ini
     * yang membuat dokumen barang keluar multi-baris aman: setiap baris sudah
     * dijepit terhadap stok produknya sendiri sebelum dimasukkan ke rencana.
     */
    private function permintaanKeluar(
        Product $product,
        array $profil,
        int $hari,
        CarbonImmutable $tanggal,
    ): int {
        if ($product->current_stock <= 0) {
            return 0;
        }

        $permintaan = $this->permintaanHari($profil, $hari, $tanggal);

        return (int) min(
            (int) $product->current_stock,
            (int) round(max(1, $this->acak(0.65, 1.5) * $permintaan)),
        );
    }

    /**
     * Restok warranted bila stok menyentuh batas aman, dengan jeda minimal
     * antar pesanan supaya tidak terjadi restok setiap hari.
     */
    private function butuhRestok(Product $product, array $profil, int $hari): bool
    {
        $terakhir = $this->lastRestock[$product->id] ?? -PHP_INT_MAX;

        if ($hari - $terakhir < (int) $profil['restock_interval']) {
            return false;
        }

        if ($product->current_stock > $product->reorder_point) {
            return false;
        }

        return true;
    }

    /**
     * Berapa unit yang perlu dibeli agar stok kembali ke level target
     * (kebijakan "order up to"): 3× batas aman.
     */
    private function kebutuhanRestok(Product $product, array $profil): int
    {
        $target = (int) round($product->reorder_point * $profil['target_multiple']);

        return max(0, $target - (int) $product->current_stock);
    }

    /**
     * Ada atau tidaknya outbound hari ini.
     *
     * Sengaja ada hari tanpa penjualan: pada agregasi, hari kosong harus
     * dihitung 0 barang keluar, bukan "tidak diketahui".
     */
    private function adaOutbound(float $activeRate): bool
    {
        return mt_rand() / mt_getrandmax() < $activeRate;
    }

    // -----------------------------------------------------------------
    // Pembantu
    // -----------------------------------------------------------------

    /**
     * Pemasok dengan lead time tercepat, lengkap dengan harga belinya.
     *
     * Catatan: `orderBy('pivot.…')` TIDAK bisa dipakai — "pivot" itu alias
     * milik Laravel saat meng-access properti pivot, bukan alias tabel SQL.
     * Harus menyebut nama tabel aslinya.
     */
    private function pemasokTerbaik(Product $product): ?Supplier
    {
        return $product->suppliers()
            ->orderBy('product_supplier.lead_time_days')
            ->first();
    }

    private function acak(float $min, float $max): float
    {
        return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    }

    private function operator(): User
    {
        return $this->master->users[self::OPERATORS[mt_rand(0, count(self::OPERATORS) - 1)]];
    }

    /**
     * Tandai notifikasi lama sebagai sudah dibaca.
     *
     * Tanpa ini lonceng akan menampilkan puluhan "belum dibaca" yang
     * membingungkan. Hanya peringatan 12 hari terakhir yang dibiarkan belum
     * dibaca — cukup untuk memperlihatkan lonceng sambil tetap realistis.
     */
    private function rapikanNotifikasi(int $hariBelumDibaca = 12): void
    {
        $batas = CarbonImmutable::now()->subDays($hariBelumDibaca)->format('Y-m-d H:i:s');

        DB::table('notifications')
            ->where('created_at', '<', $batas)
            ->update(['read_at' => $batas]);
    }
}
