<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\SalesExportService;
use App\Traits\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Ekspor penjualan untuk finance (izin `reports.export`: memuat nama, alamat, dan telepon pelanggan). Berkas dibuat di folder
 * sementara sistem (bukan storage publik), dikirim, lalu dihapus. Audit mencatat siapa, rentang, dan jumlah baris, tanpa isi data.
 */
class SalesExportController extends Controller
{
    use ApiResponse;

    /** @return array{0: string, 1: string} */
    private function dates(Request $request): array
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if (CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) >= SalesExportService::MAX_DAYS) {
            abort(422, 'Rentang tanggal terlalu panjang (maksimal '.SalesExportService::MAX_DAYS.' hari).');
        }

        return [$data['from'], $data['to']];
    }

    public function summary(Request $request, SalesExportService $export): JsonResponse
    {
        [$from, $to] = $this->dates($request);

        return $this->success('Export summary retrieved.', $export->summary($export->range($from, $to)));
    }

    public function download(Request $request, SalesExportService $export): BinaryFileResponse
    {
        [$from, $to] = $this->dates($request);
        $range = $export->range($from, $to);

        $path = tempnam(sys_get_temp_dir(), 'tuffero-sales-');
        // Lebar kolom mengikuti panjang judul (huruf tebal sedikit lebih lebar); judul biru, tetap di atas saat digulir.
        $options = new Options;
        foreach (SalesExportService::HEADERS as $i => $title) {
            $options->setColumnWidth(max(12, round(mb_strlen($title) * 1.2 + 4)), $i + 1);
        }

        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setSheetView((new SheetView)->withFreezeRow(2));
        $headerStyle = (new Style)->withFontBold(true)->withFontColor(Color::WHITE)->withBackgroundColor('2E75B6');
        $writer->addRow(new Row(array_map(fn (string $title) => Cell::fromValue($title, $headerStyle), SalesExportService::HEADERS)));

        $count = 0;
        foreach ($export->rows($range) as $values) {
            // Teks selalu sel teks: openspout menulis string berawalan = sebagai rumus, dan nama pelanggan tidak boleh jadi rumus.
            $writer->addRow(new Row(array_map(fn ($value) => is_string($value) && $value !== '' ? new StringCell($value) : Cell::fromValue($value), $values)));
            $count++;
        }

        $writer->close();

        AuditLog::record('report.sales_export', $request->user(), ['from' => $from, 'to' => $to, 'rows' => $count], $request->user()->id);

        return response()->download($path, "penjualan-finance-{$from}-sd-{$to}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store',
        ])->deleteFileAfterSend(true);
    }
}
