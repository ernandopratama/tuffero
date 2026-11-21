<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Webhook WhatsApp Cloud API. Tanpa sesi dan CSRF.
 * GET: tantangan verifikasi Meta saat alamat callback disimpan (hub.verify_token).
 * POST: keaslian dibuktikan X-Hub-Signature-256 (HMAC-SHA256 isi mentah dengan App Secret).
 * Saat ini hanya mencatat pesan yang gagal terkirim (tanpa nomor), sebagai dasar cadangan SMS (D-093 aturan 8).
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $token = config('services.whatsapp.webhook_verify_token');

        if ($token && $request->query('hub_mode') === 'subscribe' && hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('', 403);
    }

    public function receive(Request $request): JsonResponse
    {
        $secret = config('services.whatsapp.app_secret');
        $signature = (string) $request->header('X-Hub-Signature-256');

        if (! $secret || ! hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            return response()->json(['status' => 'rejected'], 403);
        }

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $status) {
                    if (($status['status'] ?? null) === 'failed') {
                        Log::warning('Pesan WhatsApp gagal terkirim.', ['message_id' => $status['id'] ?? null, 'errors' => $status['errors'] ?? null]);
                    }
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
