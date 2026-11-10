<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OrderStatusService;
use App\Services\Shipping\BiteshipShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook Biteship. Tanpa sesi dan CSRF; keaslian dibuktikan rahasia bersama di header X-Biteship-Secret
 * (diatur di dashboard Biteship, bagian autentikasi webhook) atau parameter ?secret= bila header tidak bisa diatur.
 */
class BiteshipWebhookController extends Controller
{
    public function __invoke(Request $request, BiteshipShipmentService $biteship, OrderStatusService $orders): JsonResponse
    {
        $secret = $request->header('X-Biteship-Secret') ?? $request->query('secret');

        return $biteship->handleWebhook($request->all(), is_string($secret) ? $secret : null, $orders)
            ? response()->json(['status' => 'ok'])
            : response()->json(['status' => 'rejected'], 403);
    }
}
