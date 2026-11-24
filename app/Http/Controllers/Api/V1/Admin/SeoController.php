<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncSeoData;
use App\Services\SeoChecklist;
use App\Services\SeoHealthService;
use App\Services\SeoSearchReport;
use App\Services\SeoSyncService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Halaman SEO di dashboard. Semua rute memakai izin reports.view (Super Admin dan Pemilik).
 * Data Google (Search Console dan PageSpeed) hanya tampil bila kunci terisi di server (Fase 2, D-152); tanpa itu tidak ada panggilan ke Google.
 */
class SeoController extends Controller
{
    use ApiResponse;

    public function overview(SeoHealthService $seo, SeoChecklist $checklist, SeoSyncService $sync, SeoSearchReport $report): JsonResponse
    {
        $content = $seo->content();
        $configured = $sync->configured();
        $search = $report->build(28);

        return $this->success('SEO overview retrieved.', [
            'environment' => (string) config('app.env'),
            'base_url' => rtrim((string) config('app.url'), '/'),
            'content' => $content['summary'],
            'technical' => $seo->technical(),
            'checklist' => $checklist->items($content['summary'], $sync->confirmations()),
            'google' => [
                'connected' => $configured['search_console'],
                'pagespeed' => $configured['pagespeed'],
                'sync' => $sync->status(),
                'totals' => $search['totals'],
                'previous' => $search['previous'],
                'speed' => $search['speed'],
            ],
        ]);
    }

    /** Data Search Console dan PageSpeed dari tabel lokal (periode 7, 28, atau 90 hari). */
    public function search(Request $request, SeoSearchReport $report, SeoSyncService $sync): JsonResponse
    {
        return $this->success('SEO search data retrieved.', [
            'configured' => $sync->configured(),
            'property' => config('services.google_seo.property'),
            'sync' => $sync->status(),
            'report' => $report->build((int) $request->query('days', 28)),
        ]);
    }

    /** Tombol Perbarui sekarang: antre satu sinkron; ditolak bila kunci belum diisi atau sinkron sedang berjalan. */
    public function sync(SeoSyncService $sync): JsonResponse
    {
        $configured = $sync->configured();
        if (! $configured['search_console'] && ! $configured['pagespeed']) {
            return $this->error('Kunci Google belum diisi di server.', [], 422);
        }
        if ($sync->isRunning()) {
            return $this->error('Sinkron sedang berjalan.', [], 409);
        }

        $sync->markQueued();
        SyncSeoData::dispatch();

        return $this->success('SEO sync queued.', $sync->status(), 202);
    }

    public function content(SeoHealthService $seo): JsonResponse
    {
        return $this->success('SEO content retrieved.', [
            ...$seo->content(),
            'pages' => $seo->pages()->all(),
        ]);
    }

    public function preview(Request $request, SeoHealthService $seo): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:200'],
            'lang' => ['nullable', 'in:id,en'],
        ]);

        $preview = $seo->preview($data['path'], $data['lang'] ?? 'id');

        return $preview
            ? $this->success('SEO preview retrieved.', $preview)
            : $this->error('Halaman tidak ada di daftar pratinjau.', ['path' => ['Halaman tidak ada di daftar pratinjau.']], 422);
    }

    public function check(SeoHealthService $seo): JsonResponse
    {
        return $this->success('SEO technical check completed.', $seo->runTechnical());
    }

    public function checklist(Request $request, SeoChecklist $checklist, string $key): JsonResponse
    {
        $data = $request->validate(['done' => ['required', 'boolean']]);

        $checklist->set($key, (bool) $data['done'], $request->user());

        return $this->success('SEO checklist updated.');
    }
}
