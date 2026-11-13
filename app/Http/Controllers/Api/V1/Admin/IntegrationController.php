<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\IntegrationHealthService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class IntegrationController extends Controller
{
    use ApiResponse;

    /** Status integrasi untuk halaman Pengaturan. Tanpa panggilan jaringan; isi kunci dan rahasia tidak ikut. */
    public function index(IntegrationHealthService $health): JsonResponse
    {
        $base = $health->webhookBase();

        return $this->success('Integrations retrieved.', [
            'webhook_source' => $base['source'],
            'tunnel_url' => $base['source'] === 'tunnel' ? $base['url'] : null,
            'local' => app()->environment('local'),
            'webhook_reachable' => $base['reachable'],
            'checked_at' => $health->cached()['checked_at'] ?? null,
            'services' => $health->services(),
            'integrations' => collect($health->overview())->map(fn (array $i) => [
                'key' => $i['key'],
                'title' => $i['title'],
                'status' => $i['status'],
                'issues' => $i['issues'],
                'live' => $i['live'],
                'checked_at' => $i['checked']?->toIso8601String(),
                'last_label' => $i['last_label'],
                'last_at' => $i['last']?->toIso8601String(),
                'rejected_24h' => $i['rejected'],
                'webhook_url' => $base['url'].'/api/v1/webhooks/'.$i['key'],
            ])->all(),
        ]);
    }

    /** Uji kunci Midtrans dan Biteship ke penyedia. Dibatasi lewat rute karena memanggil jaringan luar. */
    public function check(IntegrationHealthService $health): JsonResponse
    {
        return $this->success('Integration check completed.', $health->refresh());
    }
}
