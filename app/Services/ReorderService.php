<?php

namespace App\Services;

use App\Models\DailyOutboundAggregation;
use App\Models\Product;
use App\Services\Support\ReorderRecommendation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Pemantauan Reorder Point (PRD Modul 3): daftar prioritas produk yang perlu
 * dipesan ulang, diurutkan berdasarkan sisa stok dan metrik lead time.
 *
 * Logika analitik ini berada di service, bukan controller, sesuai Service
 * Pattern. Rata-rata harian dihitung langsung dari tabel OLAP dengan
 * SUM / jumlah hari — bukan rata-rata dari baris yang ada, supaya hari tanpa
 * outbound ikut dihitung sebagai nol.
 */
final class ReorderService
{
    /** Panjang histori default untuk mengukur demand. */
    public const DEFAULT_LOOKBACK_DAYS = 30;

    /** Buffer keamanan di atas lead time. */
    public const DEFAULT_SAFETY_DAYS = 7;

    /** Lead time asumsi bila produk belum punya pemasok terdaftar. */
    public const DEFAULT_LEAD_TIME_DAYS = 14;

    /**
     * Daftar rekomendasi, paling mendesak di atas.
     *
     * Urutan: produk yang belum punya data demand dianggap paling lambat
     * (daysOfCover null), lalu diurutkan dengan lead time dan sisa stok.
     *
     * @param  int|null  $categoryId  batasi ke satu kategori
     * @param  int  $limit  jumlah baris maksimal
     * @return Collection<int, ReorderRecommendation>
     */
    public function recommendations(
        ?int $categoryId = null,
        int $limit = 50,
        int $lookbackDays = self::DEFAULT_LOOKBACK_DAYS,
        bool $onlyNeedingReorder = true,
    ): Collection {
        $lookbackDays = max(1, $lookbackDays);

        $query = Product::query()->with(['category', 'suppliers']);

        if ($onlyNeedingReorder) {
            $query->needingReorder();
        }

        if ($categoryId !== null) {
            $query->where('category_id', $categoryId);
        }

        $demandPerProduct = $this->averageDailyDemandPerProduct($lookbackDays);

        $recommendations = $query->get()
            ->map(fn (Product $product) => ReorderRecommendation::fromProduct(
                product: $product,
                averageDailyDemand: $demandPerProduct[$product->id] ?? 0.0,
                safetyDays: self::DEFAULT_SAFETY_DAYS,
                defaultLeadTimeDays: self::DEFAULT_LEAD_TIME_DAYS,
            ));

        return $recommendations
            ->sort(function (ReorderRecommendation $a, ReorderRecommendation $b): int {
                // 1. Yang paling mendesak (isUrgent) selalu di atas.
                if ($a->isUrgent !== $b->isUrgent) {
                    return $a->isUrgent ? -1 : 1;
                }

                // 2. Days of cover terkecil lebih dulu; tanpa data = paling akhir.
                $coverA = $a->daysOfCover ?? PHP_INT_MAX;
                $coverB = $b->daysOfCover ?? PHP_INT_MAX;
                if ($coverA !== $coverB) {
                    return $coverA <=> $coverB;
                }

                // 3. Lead time lebih pendek lebih mendesak.
                if ($a->leadTimeDays !== $b->leadTimeDays) {
                    return $a->leadTimeDays <=> $b->leadTimeDays;
                }

                // 4. Sisa stok terkecil lebih dulu.
                return $a->currentStock <=> $b->currentStock;
            })
            ->values()
            ->take($limit);
    }

    /**
     * Rekomendasi untuk satu produk saja (dipakai halaman detail produk).
     */
    public function forProduct(Product $product, int $lookbackDays = self::DEFAULT_LOOKBACK_DAYS): ReorderRecommendation
    {
        $product->loadMissing(['category', 'suppliers']);

        $demand = $this->averageDailyDemandPerProduct(max(1, $lookbackDays))[$product->id] ?? 0.0;

        return ReorderRecommendation::fromProduct(
            product: $product,
            averageDailyDemand: $demand,
            safetyDays: self::DEFAULT_SAFETY_DAYS,
            defaultLeadTimeDays: self::DEFAULT_LEAD_TIME_DAYS,
        );
    }

    /**
     * Rata-rata pcs/hari per produk pada jendela lookback.
     *
     * Pembagi adalah JUMLAH HARI dalam jendela, bukan jumlah baris. Kalau
     * produk hanya punya 3 baris outbound dalam 30 hari, rata-ratanya tetap
     * total/30 — hari tanpa barang keluar berarti 0, bukan "tidak diketahui".
     *
     * @return array<int, float> product_id => rata-rata per hari
     */
    private function averageDailyDemandPerProduct(int $lookbackDays): array
    {
        $since = CarbonImmutable::now()->subDays($lookbackDays - 1)->startOfDay();

        $rows = DailyOutboundAggregation::query()
            ->where('date', '>=', $since->format('Y-m-d H:i:s'))
            // select('product_id') wajib: selectRaw() akan menggantinya.
            ->select('product_id')
            ->selectRaw('SUM(total_qty_out) as total')
            ->groupBy('product_id')
            ->get();

        $demand = [];

        foreach ($rows as $row) {
            $demand[(int) $row->product_id] = (int) $row->total / $lookbackDays;
        }

        return $demand;
    }
}
