<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MarketingReminderService;
use Illuminate\Http\JsonResponse;

/**
 * Ringkasan pengingat untuk Hermes Agent (D-079), hanya baca, dijaga token (middleware HermesToken). Berisi hitungan
 * dan judul draf; tidak ada isi draf, data pelanggan, pesanan, atau harga. Hermes merangkainya menjadi pesan Telegram.
 */
class HermesReminderController extends Controller
{
    public function __invoke(MarketingReminderService $reminders): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Reminders retrieved.', 'data' => $reminders->summary()]);
    }
}
