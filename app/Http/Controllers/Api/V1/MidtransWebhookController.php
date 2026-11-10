<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MidtransService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifikasi HTTP Midtrans (BR-11). Tanpa sesi dan CSRF; keaslian dibuktikan oleh signature_key.
 */
class MidtransWebhookController extends Controller
{
    public function __invoke(Request $request, MidtransService $midtrans): JsonResponse
    {
        // 200 juga untuk notifikasi ulang, supaya Midtrans berhenti mengirim. Selain itu 403 tanpa rincian.
        return $midtrans->handleNotification($request->all())
            ? response()->json(['status' => 'ok'])
            : response()->json(['status' => 'rejected'], 403);
    }
}
