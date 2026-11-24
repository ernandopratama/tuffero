<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MarketingDraftMetricRequest;
use App\Models\MarketingDraft;
use App\Models\MarketingDraftMetric;
use App\Services\MarketingMetricService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Metrik harian konten tayang, diisi manual (rapat 14 Nov 2026 poin 28). Lihat: `marketing_drafts.view`.
 * Tambah, ubah, hapus: `content_metrics.manage` (Marketing, Admin Toko, Super Admin) dengan MFA. Catatan dari draf lain dijawab 404 (`scopeBindings` di rute).
 */
class MarketingDraftMetricController extends Controller
{
    use ApiResponse;

    public function __construct(private MarketingMetricService $metrics) {}

    public function index(MarketingDraft $draft): JsonResponse
    {
        return $this->success('Metrics retrieved.', $this->payload($draft));
    }

    /** Ringkasan untuk kartu dashboard dan halaman Performa Konten (`marketing_drafts.view`). */
    public function overview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', Rule::in([7, 30, 90])],
            'platform' => ['nullable', Rule::in(array_keys(MarketingDraftMetric::PLATFORMS))],
        ]);

        return $this->success('Overview retrieved.', $this->metrics->overview((int) ($data['days'] ?? 7), $data['platform'] ?? null));
    }

    public function top(): JsonResponse
    {
        return $this->success('Top content retrieved.', ['platforms' => $this->metrics->top()]);
    }

    public function store(MarketingDraftMetricRequest $request, MarketingDraft $draft): JsonResponse
    {
        $this->metrics->create($draft, $request->validated(), $request->user());

        return $this->success('Metric added.', $this->payload($draft), 201);
    }

    public function update(MarketingDraftMetricRequest $request, MarketingDraft $draft, MarketingDraftMetric $metric): JsonResponse
    {
        $this->metrics->update($draft, $metric, $request->validated(), $request->user());

        return $this->success('Metric updated.', $this->payload($draft));
    }

    public function destroy(Request $request, MarketingDraft $draft, MarketingDraftMetric $metric): JsonResponse
    {
        $this->metrics->delete($draft, $metric, $request->user());

        return $this->success('Metric deleted.', $this->payload($draft));
    }

    /** @return array<string, mixed> */
    private function payload(MarketingDraft $draft): array
    {
        $items = $draft->metrics()->with('recorder:id,name')->orderByDesc('measured_on')->orderBy('platform')->get();

        return [
            'items' => $items->map(fn (MarketingDraftMetric $metric) => [
                'id' => $metric->public_id,
                ...$metric->only(['platform', 'post_url', 'measured_on', ...MarketingDraftMetric::COUNTS, ...MarketingDraftMetric::OPTIONAL_COUNTS]),
                'recorder' => $metric->recorder?->name,
                'updated_at' => $metric->updated_at,
            ])->values(),
            'summary' => $this->metrics->summary($draft),
        ];
    }
}
