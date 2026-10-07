<?php

namespace App\Jobs;

use App\Enums\MovementType;
use App\Models\DailyOutboundAggregation;
use App\Models\InventoryMovement;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rangkum ribuan baris `inventory_movements` menjadi satu baris per pasangan
 * (produk, tanggal) di tabel `daily_outbound_aggregations` — PRD Modul 4.
 *
 * Dijalankan asinkron setiap tengah malam lewat scheduler. Job ini idempoten:
 * menjalankan ulang untuk rentang tanggal yang sama memperbarui baris yang sudah
 * ada (bukan menambah duplikat), sekaligus menghapus baris agregasi yang
 * sumber datanya sudah hilang.
 */
class AggregateDailyOutboundJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** Jumlah percobaan bila gagal (mis. deadlock). */
    public int $tries = 3;

    /** Jeda antar percobaan, dalam detik. */
    public int $backoff = 60;

    /** Batas waktu eksekusi, dalam detik. */
    public int $timeout = 900;

    /**
     * @param  string|null  $forDate  tanggal target format Y-m-d; null = kemarin
     * @param  int|null  $lookbackDays  bila diisi, agregasi ulang rentang
     *                                  lookbackDays hari sekaligus (untuk backfill)
     */
    public function __construct(
        public readonly ?string $forDate = null,
        public readonly ?int $lookbackDays = null,
    ) {}

    /**
     * @return int jumlah baris agregasi yang tersimpan
     */
    public function handle(): int
    {
        $endDate = $this->forDate !== null
            ? CarbonImmutable::parse($this->forDate)->startOfDay()
            : CarbonImmutable::yesterday()->startOfDay();

        $startDate = $this->lookbackDays !== null
            ? $endDate->subDays(max(1, $this->lookbackDays) - 1)
            : $endDate;

        $rows = $this->fetchAggregates($startDate, $endDate);

        $written = $this->persist($rows);
        $removed = $this->pruneStaleRows($rows, $startDate, $endDate);

        Log::info('Smart-Stock: agregasi harian selesai', [
            'from' => $startDate->toDateString(),
            'to' => $endDate->toDateString(),
            'rows_upserted' => $written,
            'rows_pruned' => $removed,
        ]);

        return $written;
    }

    /**
     * Kumulatif barang keluar per produk per hari.
     *
     * `quantity` di buku besar bersifat bertanda (outbound = negatif), jadi
     * dijumlahkan dengan ABS(). Hanya tipe outbound yang dihitung: inbound dan
     * stock opname bukan permintaan.
     *
     * @return Collection<int, object>
     */
    private function fetchAggregates(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
    {
        $dateExpression = $this->dateExpression();

        /** @var Collection<int, object> $rows */
        $rows = InventoryMovement::query()
            // product_id harus disebut eksplisit: selectRaw() mengganti daftar
            // kolom, bukan menambahkannya.
            ->select('product_id')
            ->selectRaw($dateExpression.' as agg_date, SUM(ABS(quantity)) as total_qty_out')
            ->where('movement_type', MovementType::Outbound->value)
            // Batas query harus 'Y-m-d H:i:s', karena kolom timestamp ditulis
            // Eloquent dengan format itu dan SQLite menyimpannya apa adanya
            // sebagai teks. String 'Y-m-d' akan membuat baris terakhir hari
            // tersebut terlewat.
            ->whereBetween('movement_date', [
                $startDate->startOfDay()->format('Y-m-d H:i:s'),
                $endDate->endOfDay()->format('Y-m-d H:i:s'),
            ])
            ->groupBy('product_id')
            ->groupByRaw($dateExpression)
            ->orderBy('product_id')
            ->get();

        return $rows;
    }

    /**
     * Ekspresi SQL untuk memotong timestamp menjadi tanggal.
     *
     * Harus per-driver: DATE() hanya ada di MySQL/MariaDB, PostgreSQL memakai
     * cast, dan SQLite memakai strftime(). Laravel tidak mengekspos API publik
     * untuk pemotongan tanggal di dalam GROUP BY (Grammar::whereDate bersifat
     * protected, dan tidak ada compileDate di MySqlGrammar), jadi dipetakan di
     * sini.
     */
    private function dateExpression(): string
    {
        $column = InventoryMovement::query()->getModel()->qualifyColumn('movement_date');

        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m-%d', {$column})",
            'pgsql' => "{$column}::date",
            default => "DATE({$column})",
        };
    }

    /**
     * Tulis hasil agregasi. updateOrCreate membuat job ini idempoten.
     *
     * @param  Collection<int, object>  $rows
     */
    private function persist(Collection $rows): int
    {
        foreach ($rows as $row) {
            DailyOutboundAggregation::updateOrCreate(
                [
                    'product_id' => (int) $row->product_id,
                    // WAJIB objek Carbon, bukan string Y-m-d: nilai yang
                    // tersimpan di SQLite berbentuk Y-m-d H:i:s, sehingga
                    // pencarian dengan string Y-m-d tidak akan cocok dan
                    // updateOrCreate akan menabrak UNIQUE constraint.
                    'date' => CarbonImmutable::parse($row->agg_date)->startOfDay(),
                ],
                ['total_qty_out' => (int) $row->total_qty_out],
            );
        }

        return $rows->count();
    }

    /**
     * Hapus baris agregasi di rentang yang tidak lagi punya movements outbound,
     * supaya menjalankan ulang job tetap menghasilkan tabel yang konsisten.
     *
     * @param  Collection<int, object>  $rows
     */
    private function pruneStaleRows(Collection $rows, CarbonImmutable $startDate, CarbonImmutable $endDate): int
    {
        $fresh = [];

        foreach ($rows as $row) {
            $fresh[$row->product_id.'|'.CarbonImmutable::parse($row->agg_date)->toDateString()] = true;
        }

        $removed = 0;

        /** @var Collection<int, DailyOutboundAggregation> $existingRows */
        $existingRows = DailyOutboundAggregation::query()
            ->whereBetween('date', [
                $startDate->startOfDay()->format('Y-m-d H:i:s'),
                $endDate->endOfDay()->format('Y-m-d H:i:s'),
            ])
            ->get();

        foreach ($existingRows as $existing) {
            $key = $existing->product_id.'|'.$existing->date->toDateString();

            if (! isset($fresh[$key])) {
                $existing->delete();
                $removed++;
            }
        }

        return $removed;
    }
}
