<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\SecurityHealthService;
use App\Services\StuckOrderService;
use App\Services\SystemHealthService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class SystemHealthController extends Controller
{
    use ApiResponse;

    /** Pemeriksaan jalannya sistem untuk halaman Pengaturan. Hanya Super Admin; semua pemeriksaan lokal. */
    /** Tandai galat log yang ada sekarang sudah ditangani (berkas log tidak diubah). */
    public function acknowledgeLog(SystemHealthService $health): JsonResponse
    {
        $health->acknowledgeLogErrors();

        return $this->success('Log errors acknowledged.');
    }

    public function index(SystemHealthService $health, StuckOrderService $stuck, SecurityHealthService $security): JsonResponse
    {
        return $this->success('System health retrieved.', [
            'generated_at' => now()->toIso8601String(),
            'checks' => $health->checks(),
            'stuck_orders' => $stuck->checks(),
            'security' => $security->checks(),
        ]);
    }
}
