<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Services\ForecastAccuracyService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Evaluasi akurasi algoritma prediksi (PRD Modul 3 — "Evaluasi Akurasi &
 * Ekspor Laporan": mengukur tingkat eror algoritma).
 *
 * Hanya Manager Bisnis, sama seperti dasbor & reorder.
 */
class AccuracyController extends Controller
{
    public function __construct(private readonly ForecastAccuracyService $accuracy) {}

    /**
     * Halaman "Evaluasi Akurasi": metrik agregat + rincian per produk.
     */
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $months = $this->accuracy->selectableMonths();

        // Default: bulan terbaru yang SUDAH bisa dinilai. Tanpa ini halaman
        // selalu terbuka di bulan berjalan yang isinya masih setengah jadi dan
        // hanya menampilkan peringatan "belum bisa dinilai".
        $selected = $filters['month'] ?? $this->bulanTerbaruYangNilai($months);

        $report = $this->accuracy->evaluate($selected);

        return Inertia::render('Analytics/Accuracy', [
            'report' => $report->toArray(),
            'coverage' => $report->coveragePercent(),
            'rows' => $report->rows,
            'trend' => $this->accuracy->trend(),
            'months' => $months,
            'filters' => ['month' => $report->targetMonth],
        ]);
    }

    /**
     * Bulan pertama (terbaru) yang datanya sudah lengkap.
     *
     * @param  array<int, array{value: string, label: string, is_evaluable: bool}>  $months
     */
    private function bulanTerbaruYangNilai(array $months): string
    {
        foreach ($months as $month) {
            if ($month['is_evaluable']) {
                return $month['value'];
            }
        }

        // Tidak ada yang bisa dinilai — jatuh ke bulan apa pun supaya halaman
        // tetap tampil (dengan kondisi "belum ada data"), bukan error.
        return $months[0]['value'] ?? now()->startOfMonth()->toDateString();
    }

    /**
     * Ekspor CSV hasil evaluasi akurasi untuk satu bulan.
     */
    public function exportCsv(Request $request)
    {
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $report = $this->accuracy->evaluate($filters['month'] ?? now()->startOfMonth()->toDateString());

        $header = [
            'bulan',
            'sku',
            'nama_produk',
            'prediksi',
            'aktual',
            'selisih',
            'error_persen',
            'arah',
            'confidence_persen',
        ];

        return response()->streamDownload(function () use ($report, $header): void {
            $handle = fopen('php://output', 'wb');

            // BOM supaya Excel membaca UTF-8 dengan benar.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $header);

            foreach ($report->rows as $row) {
                fputcsv($handle, [
                    $report->label,
                    $row['sku'],
                    $row['name'],
                    $row['predicted_qty'],
                    $row['actual_qty'],
                    $row['error'],
                    // Error persen tidak ada saat aktual nol; sel kosong lebih
                    // jujur daripada 0 yang terlihat seperti prediksi sempurna.
                    $row['error_pct'] ?? '',
                    ['over' => 'Over', 'under' => 'Under', 'exact' => 'Tepat'][$row['direction']],
                    $row['confidence_score'],
                ]);
            }

            // Blok ringkasan di akhir supaya angka agregat ikut terekspor.
            fputcsv($handle, []);
            fputcsv($handle, ['RINGKASAN']);
            fputcsv($handle, ['Bulan', $report->label]);
            fputcsv($handle, ['Bulan sudah selesai', $report->isEvaluable ? 'ya' : 'belum']);
            fputcsv($handle, ['Jumlah prediksi', $report->predictionCount]);
            fputcsv($handle, ['Punya data aktual', $report->comparedCount]);
            fputcsv($handle, ['Aktual nol', $report->zeroActualCount]);
            fputcsv($handle, ['Total prediksi', $report->totalPredicted]);
            fputcsv($handle, ['Total aktual', $report->totalActual]);
            fputcsv($handle, ['MAE (pcs)', $report->mae ?? '']);
            fputcsv($handle, ['MAPE (%)', $report->mape ?? '']);
            fputcsv($handle, ['WAPE (%)', $report->wape ?? '']);
            fputcsv($handle, ['RMSE (pcs)', $report->rmse ?? '']);
            fputcsv($handle, ['Bias (pcs)', $report->bias ?? '']);

            fclose($handle);
        }, 'akurasi-prediksi-'.$report->targetMonth.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
