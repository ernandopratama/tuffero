<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MarketingMetricService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Metrik konten untuk Hermes Agent (D-079, D-150), hanya baca, dijaga token baca khusus (HermesToken:read). Berisi angka
 * metrik, judul draf, dan platform; tanpa tautan posting, data pelanggan, pesanan, atau harga. Hermes tidak mengambil
 * data dari TikTok atau Instagram: isinya catatan manual tim di dashboard.
 */
class HermesMetricController extends Controller
{
    public const DEFAULT_DAYS = 90;

    public const MAX_DAYS = 365;

    public function __invoke(Request $request, MarketingMetricService $metrics): JsonResponse
    {
        $days = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_DAYS],
        ])['days'] ?? self::DEFAULT_DAYS;

        return response()->json(['success' => true, 'message' => 'Metrics retrieved.', 'data' => $metrics->export((int) $days)]);
    }
}
