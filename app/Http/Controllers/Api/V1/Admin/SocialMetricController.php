<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SocialAccountMetricRequest;
use App\Http\Requests\Admin\SocialAudienceRequest;
use App\Models\AccountAudienceSnapshot;
use App\Models\SocialAccountMetric;
use App\Services\SocialMetricService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Performa akun media sosial, diisi manual (D-151 tahap 1). Lihat: `marketing_drafts.view`.
 * Tambah, ubah, hapus: `social_metrics.manage` (Marketing, Admin Toko, Super Admin) dengan MFA.
 */
class SocialMetricController extends Controller
{
    use ApiResponse;

    public function __construct(private SocialMetricService $metrics) {}

    public function overview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', Rule::in([7, 30, 90])],
            'period' => ['nullable', 'integer', Rule::in(SocialAccountMetric::PERIODS)],
            'platform' => ['nullable', Rule::in(array_keys(SocialAccountMetric::PLATFORMS))],
            'include_test' => ['nullable', 'boolean'],
        ]);

        return $this->success('Overview retrieved.', $this->metrics->overview(
            (int) ($data['days'] ?? 30),
            (int) ($data['period'] ?? 1),
            $data['platform'] ?? null,
            $request->boolean('include_test'),
        ));
    }

    public function store(SocialAccountMetricRequest $request): JsonResponse
    {
        $metric = $this->metrics->create($request->validated(), $request->user());

        return $this->success('Metric added.', $this->metrics->present($metric), 201);
    }

    public function update(SocialAccountMetricRequest $request, SocialAccountMetric $metric): JsonResponse
    {
        $metric = $this->metrics->update($metric, $request->validated(), $request->user());

        return $this->success('Metric updated.', $this->metrics->present($metric));
    }

    public function destroy(Request $request, SocialAccountMetric $metric): JsonResponse
    {
        $this->metrics->delete($metric, $request->user());

        return $this->success('Metric deleted.');
    }

    public function destroyTestData(Request $request): JsonResponse
    {
        return $this->success('Test data deleted.', ['deleted' => $this->metrics->deleteTestData($request->user())]);
    }

    public function saveAudience(SocialAudienceRequest $request): JsonResponse
    {
        $input = $request->validated();
        $data = [];
        foreach (AccountAudienceSnapshot::GROUPS as $group) {
            $data[$group] = [];
            foreach ($input[$group] ?? [] as $row) {
                $data[$group][] = ['label' => $row['label'], 'percent' => (float) $row['percent']];
            }
        }
        $snapshot = $this->metrics->saveAudience([
            'platform' => $input['platform'],
            'month' => $input['month'].'-01',
            'is_test' => $input['is_test'],
            'data' => $data,
        ], $request->user());

        return $this->success('Audience saved.', ['id' => $snapshot->public_id], 201);
    }

    public function destroyAudience(Request $request, AccountAudienceSnapshot $snapshot): JsonResponse
    {
        $this->metrics->deleteAudience($snapshot, $request->user());

        return $this->success('Audience deleted.');
    }
}
