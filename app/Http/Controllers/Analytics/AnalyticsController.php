<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\DailyOutboundAggregation;
use App\Models\MonthlyDemandPrediction;
use App\Models\Product;
use App\Services\ReorderService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dasbor Manager Bisnis (PRD Modul 3): prediksi permintaan, pemantauan
 * reorder point, dan evaluasi akurasi.
 */
class AnalyticsController extends Controller
{
    public function __construct(private readonly ReorderService $reorderService) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m-d'],
            'category_id' => ['nullable', 'integer'],
        ]);

        $selectedMonth = isset($filters['month'])
            ? $filters['month']
            : now()->addMonthNoOverflow()->startOfMonth()->toDateString();

        return Inertia::render('Analytics/Dashboard', [
            'kpi' => $this->kpi($selectedMonth),
            'trend' => $this->demandTrend(),
            'predictions' => $this->predictions($selectedMonth, $filters['category_id'] ?? null),
            'reorderTop' => $this->reorderService
                ->recommendations(categoryId: $filters['category_id'] ?? null, limit: 8)
                ->values(),
            'availableMonths' => $this->availableMonths(),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'filters' => [
                'month' => $selectedMonth,
                'category_id' => $filters['category_id'] ?? null,
            ],
        ]);
    }

    /**
     * Pemantauan reorder point (PRD Modul 3).
     */
    public function reorder(Request $request): Response
    {
        $filters = $request->validate([
            'category_id' => ['nullable', 'integer'],
            'only_needing_reorder' => ['nullable', 'boolean'],
        ]);

        $recommendations = $this->reorderService->recommendations(
            categoryId: $filters['category_id'] ?? null,
            limit: 200,
            onlyNeedingReorder: $request->boolean('only_needing_reorder', true),
        );

        return Inertia::render('Analytics/Reorder', [
            'recommendations' => $recommendations->values(),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'filters' => [
                'category_id' => $filters['category_id'] ?? null,
                'only_needing_reorder' => $request->boolean('only_needing_reorder', true),
            ],
        ]);
    }

    /**
     * Ekspor CSV (PRD Modul 3: kapabilitas unduh dokumen).
     */
    public function exportCsv(Request $request, ReorderService $reorderService)
    {
        $recommendations = $reorderService->recommendations(limit: 1000);

        $rows = $recommendations->map(fn ($item): array => [
            'sku' => $item->sku,
            'nama_produk' => $item->name,
            'kategori' => $item->categoryName ?? '-',
            'stok_saat_ini' => $item->currentStock,
            'batas_aman' => $item->reorderPoint,
            'lead_time_hari' => $item->leadTimeDays,
            'jumlah_pemasok' => $item->supplierCount,
            'demand_harian' => $item->averageDailyDemand,
            'hari_stok_tahan' => $item->daysOfCover ?? '-',
            'saran_pesan' => $item->suggestedOrderQty,
            'mendesak' => $item->isUrgent ? 'ya' : 'tidak',
        ])->all();

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');

            // BOM supaya Excel membaca UTF-8 dengan benar.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, array_keys($rows[0] ?? ['sku' => '']));

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, 'reorder-point-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // -----------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------

    /**
     * @return array<string, int|float|null>
     */
    private function kpi(string $month): array
    {
        $predictions = MonthlyDemandPrediction::query()
            ->whereDate('target_month', $month);

        return [
            'total_products' => Product::count(),
            'needs_reorder' => Product::needingReorder()->count(),
            'out_of_stock' => Product::where('current_stock', 0)->count(),
            'predicted_total' => (int) (clone $predictions)->sum('predicted_qty'),
            'predicted_products' => (int) (clone $predictions)->count(),
            'average_confidence' => round((float) (clone $predictions)->avg('confidence_score'), 2),
            'outbound_this_month' => (int) DailyOutboundAggregation::query()
                ->whereBetween('date', [
                    now()->startOfMonth()->format('Y-m-d H:i:s'),
                    now()->endOfMonth()->format('Y-m-d H:i:s'),
                ])
                ->sum('total_qty_out'),
        ];
    }

    /**
     * Deret total permintaan per bulan untuk grafik (dari tabel prediksi).
     *
     * Setiap titik ditandai `status`, karena ketiganya bukan hal yang sama:
     *
     *  - `riwayat`  bulan sudah lewat. Nilainya hasil hitung ulang (backtest):
     *                 permintaan saat itu sudah diketahui, jadi ini angka yang
     *                 bisa dinilai kebenarannya (dipakai Evaluasi Akurasi).
     *  - `berjalan` bulan sekarang. Diproyeksikan dari histori penuh sampai
     *                 akhir bulan sebelumnya, jadi sahih -- dan justru bulan
     *                 yang paling operatif, karena menjawab "stok cukup untuk
     *                 bulan ini?".
     *  - `proyeksi` bulan depan, belum ada datanya sama sekali.
     *
     * Tanpa penanda ini, ketiganya ditampilkan sebagai "Total kuantitas
     * prediksi" yang sama artinya, dan deret yang lompat bulan (karena bulan
     * berjalan dilewati) terlihat seperti data rusak.
     *
     * @return array<int, array<string, mixed>>
     */
    private function demandTrend(): array
    {
        $bulanIni = now()->startOfMonth();

        return MonthlyDemandPrediction::query()
            ->select('target_month')
            ->selectRaw('SUM(predicted_qty) as total_predicted, AVG(confidence_score) as avg_confidence')
            ->groupBy('target_month')
            ->orderBy('target_month')
            ->get()
            ->map(function ($row) use ($bulanIni): array {
                $bulan = Carbon::parse($row->target_month)->startOfMonth();

                return [
                    'month' => $bulan->format('Y-m'),
                    'label' => $bulan->translatedFormat('M Y'),
                    'total_predicted' => (int) $row->total_predicted,
                    'avg_confidence' => round((float) $row->avg_confidence, 2),
                    'status' => match (true) {
                        $bulan->lt($bulanIni) => 'riwayat',
                        $bulan->eq($bulanIni) => 'berjalan',
                        default => 'proyeksi',
                    },
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function predictions(string $month, ?int $categoryId)
    {
        return MonthlyDemandPrediction::query()
            ->with('product:id,sku,name,current_stock,reorder_point,category_id')
            ->whereDate('target_month', $month)
            ->when($categoryId, fn ($query) => $query->whereHas(
                'product',
                fn ($productQuery) => $productQuery->where('category_id', $categoryId),
            ))
            ->join('products', 'products.id', '=', 'monthly_demand_predictions.product_id')
            ->orderByDesc('predicted_qty')
            ->get()
            ->map(fn (MonthlyDemandPrediction $prediction): array => [
                'product_id' => $prediction->product_id,
                'sku' => $prediction->product?->sku,
                'name' => $prediction->product?->name,
                'predicted_qty' => $prediction->predicted_qty,
                'confidence_score' => (float) $prediction->confidence_score,
                'current_stock' => $prediction->product?->current_stock,
                'calculated_at' => $prediction->calculated_at?->toIso8601String(),
            ])
            ->values();
    }

    /**
     * @return array<int, string>
     */
    private function availableMonths(): array
    {
        // PENTING: `->distinct()` TANPA `->select()` adalah `SELECT DISTINCT *`.
        // Karena (product_id, target_month) unik, tidak ada baris yang pernah
        // sama, jadi tidak ada yang ter-dedup: hasilnya satu baris per PRODUK,
        // bukan per bulan. `LIMIT 12` lalu mengambil 12 baris terbaru, dan
        // karena semuanya dari bulan yang sama, dropdown berisi satu bulan
        // yang tertulis dua belas kali.
        //
        // Pola yang benar sudah dipakai `ForecastAccuracyService::selectableMonths()`.
        return MonthlyDemandPrediction::query()
            ->select('target_month')
            ->distinct()
            ->orderByDesc('target_month')
            ->limit(12)
            ->get()
            ->map(fn (MonthlyDemandPrediction $row): string => $row->target_month->toDateString())
            ->all();
    }
}
