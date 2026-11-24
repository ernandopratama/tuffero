<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerNotification;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Refund;
use App\Services\CustomerNotifier;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Kirim ulang email otomatis ke pelanggan oleh CS (izin `emails.send`, MFA terdaftar). Pesan harus milik data yang sedang
 * dibuka (refund, pengajuan pembatalan, atau pesanan): id pesan milik data lain dijawab 404. Alamat diambil server dari pesanan.
 */
class CustomerNotificationController extends Controller
{
    use ApiResponse;

    public function resend(Request $request, CustomerNotifier $notifier): JsonResponse
    {
        // Dicari lewat id publik (ULID) karena rute membawa dua model (data dan pesan), jadi pengikatan otomatis tidak dipakai.
        $notification = CustomerNotification::where('public_id', (string) $request->route('notification'))->firstOrFail();
        $target = $request->route('order') ?? $request->route('refund') ?? $request->route('cancellation');

        abort_unless($this->belongsTo($notification, $target), 404);

        try {
            $notifier->resend($notification, $request->user());
        } catch (Throwable $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }

            // Tanpa pesan galat: bisa memuat alamat email.
            Log::warning('Customer notification resend failed', ['id' => $notification->id, 'error' => $e::class]);

            return $this->error('Email belum bisa dikirim ulang. Coba lagi nanti atau hubungi pelanggan lewat WhatsApp.', [], 502);
        }

        return $this->success('Email queued.', ['status' => $notification->fresh()->status]);
    }

    private function belongsTo(CustomerNotification $notification, Order|Refund|OrderCancellationRequest $target): bool
    {
        return CustomerNotification::query()->whereKey($notification->id)->forTarget($target)->exists();
    }
}
