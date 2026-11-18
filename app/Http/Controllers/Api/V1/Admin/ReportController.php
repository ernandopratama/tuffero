<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\ReportService;
use App\Traits\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    use ApiResponse;

    /**
     * Ekspor rekap Laporan sebagai xlsx (D-130, D-131): jenis => [nama sheet, nama berkas, kolom (kunci => judul)].
     * Judul kolom sama dengan tabel di layar. Hanya angka per periode, tanpa data pelanggan.
     */
    private const EXPORTS = [
        'returns' => ['Rekap Retur', 'rekap-retur', [
            'label' => 'Periode', 'submitted' => 'Pengajuan', 'returns' => 'Retur', 'claims' => 'Klaim', 'rejected' => 'Ditolak',
            'resend' => 'Kirim ulang', 'voucher' => 'Voucher', 'refunded_count' => 'Refund', 'refunded_return' => 'Dana retur',
            'refunded_claim' => 'Dana klaim', 'refunded_total' => 'Total dana',
        ]],
        'cancellations' => ['Pesanan Dibatalkan', 'pesanan-dibatalkan', [
            'label' => 'Periode', 'cancelled' => 'Dibatalkan', 'unpaid' => 'Belum dibayar', 'paid' => 'Sudah dibayar',
            'paid_value' => 'Nilai terbayar', 'refunded' => 'Dana dikembalikan',
        ]],
        'revenue' => ['Pendapatan', 'pendapatan', [
            'label' => 'Periode', 'gross' => 'Pendapatan kotor', 'refunded' => 'Dana dikembalikan', 'net' => 'Pendapatan bersih',
            'shipping_subsidy' => 'Subsidi ongkir',
        ]],
    ];

    public function index(Request $request, ReportService $reports): JsonResponse
    {
        return $this->success('Report retrieved.', $reports->build($request->user(), $this->period($request)));
    }

    /**
     * Periode tampil (tanggal Jakarta, kedua ujung termasuk) dan pengelompokannya. Tanpa parameter: bulan ini sampai hari ini, per hari.
     * Rentang paling panjang 366 hari; per hari 92 hari supaya grafik terbaca dan jumlah kueri terbatas.
     *
     * @return array{from: string, to: string, group: string}
     */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group' => ['nullable', 'in:'.implode(',', array_keys(ReportService::GROUPS))],
        ]);
        $period = array_merge(ReportService::defaultPeriod(), array_filter($data));

        $days = CarbonImmutable::parse($period['from'])->diffInDays(CarbonImmutable::parse($period['to'])) + 1;
        $max = $period['group'] === 'day' ? ReportService::MAX_DAYS_DAILY : ReportService::MAX_DAYS;
        if ($days > $max) {
            throw ValidationException::withMessages(['to' => $period['group'] === 'day'
                ? "Per hari paling banyak {$max} hari. Pilih per minggu atau per bulan untuk rentang lebih panjang."
                : "Rentang paling panjang {$max} hari."]);
        }

        return $period;
    }

    /**
     * Rekap Laporan sebagai xlsx (`reports.view`, sama dengan yang melihat tabelnya). Berkas dibuat di folder sementara sistem lalu
     * dihapus setelah dikirim. Jenis dibatasi rute (returns, cancellations, revenue). Periode dan pengelompokan sama dengan yang tampil.
     */
    public function export(Request $request, ReportService $reports, string $kind): BinaryFileResponse
    {
        $period = $this->period($request);
        $rows = match ($kind) {
            'returns' => $reports->returns($period),
            'cancellations' => $reports->cancellations($period)['rows'],
            'revenue' => $reports->revenueRows($period),
            default => abort(404),
        };
        [$sheet, $file, $columns] = self::EXPORTS[$kind];

        $path = tempnam(sys_get_temp_dir(), 'tuffero-report-');
        $writer = new Writer(new Options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName($sheet);
        $writer->getCurrentSheet()->setSheetView((new SheetView)->withFreezeRow(2));
        $headerStyle = (new Style)->withFontBold(true)->withFontColor(Color::WHITE)->withBackgroundColor('2E75B6');
        $writer->addRow(new Row(array_map(fn (string $title) => Cell::fromValue($title, $headerStyle), array_values($columns))));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(fn (string $key) => $row[$key], array_keys($columns))));
        }

        $writer->close();

        AuditLog::record("report.{$kind}_export", $request->user(), $period + ['rows' => count($rows)], $request->user()->id);

        return response()->download($path, "{$file}-{$period['group']}-{$period['from']}-sd-{$period['to']}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store',
        ])->deleteFileAfterSend(true);
    }
}
