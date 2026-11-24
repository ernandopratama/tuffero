<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderCancellationRequest as CancellationRequest;
use App\Models\PaymentEvent;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\StaffEvent;
use Illuminate\Support\Facades\DB;

/**
 * Refund otomatis lewat Midtrans untuk pembatalan pesanan terbayar yang sudah di-ACC Admin Toko (D-138 tahap 4). Bawaan MATI
 * (`cancellation.auto_refund_enabled`): dinyalakan setelah akun produksi Midtrans, aktivasi refund, dan saldo terverifikasi.
 * Hanya kartu dan QRIS; virtual account tidak punya refund di Midtrans, jadi tetap manual (`CancellationService::markRefunded`).
 * Jumlah selalu total pesanan dari database, tidak pernah dari browser.
 */
class PaymentRefundService
{
    /** Metode bayar yang punya Refund API Midtrans dan aktif di Tuffero. */
    private const AUTO_TYPES = ['credit_card', 'qris', 'gopay', 'shopeepay'];

    public function __construct(private MidtransService $midtrans, private StaffNotifier $notifier, private CustomerNotifier $customers) {}

    public static function enabled(): bool
    {
        return (string) (Setting::where('key', 'cancellation.auto_refund_enabled')->value('value') ?? '0') === '1';
    }

    /** Event bayar terakhir yang melunasi pesanan (settlement atau capture). */
    private function paidEvent(Order $order): ?PaymentEvent
    {
        return PaymentEvent::where('order_id', $order->id)->whereIn('transaction_status', ['settlement', 'capture'])->latest('id')->first();
    }

    /** Pesanan ini bisa direfund otomatis: metode bayarnya didukung dan Midtrans terkonfigurasi. */
    public function eligible(Order $order): bool
    {
        $type = $this->paidEvent($order)?->payment_type;

        return $type !== null && in_array($type, self::AUTO_TYPES, true) && $this->midtrans->enabled();
    }

    /** Tombol "Coba refund otomatis" tampil: pengajuan disetujui, dana menunggu, fitur menyala, dan metode didukung. */
    public function available(CancellationRequest $request, Order $order): bool
    {
        return self::enabled() && $request->status === CancellationRequest::APPROVED && $order->payment_status === 'refund_pending' && $this->eligible($order);
    }

    /**
     * Mencoba mengembalikan dana lewat Midtrans. Kunci baris pesanan dan pengajuan menahan klik ganda dan ACC bersamaan; `refund_key` =
     * nomor pengajuan membuat percobaan ulang aman di sisi Midtrans. Tidak ada percobaan ulang di latar belakang (staf bisa
     * mentransfer manual sementara itu): hanya percobaan pertama setelah ACC dan tombol eksplisit.
     *
     * @return string `skipped` (tidak berlaku), `done`, atau `failed`
     */
    public function attempt(CancellationRequest $request, ?User $actor = null): string
    {
        return DB::transaction(function () use ($request, $actor) {
            $order = Order::whereKey($request->order_id)->lockForUpdate()->firstOrFail();
            $locked = CancellationRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $this->available($locked, $order)) {
                return 'skipped';
            }

            $event = $this->paidEvent($order);
            $void = $event->payment_type === 'credit_card' && $event->transaction_status === 'capture';
            $result = $this->midtrans->reverse($order, $locked->request_number, "Pembatalan {$locked->request_number}", $void);
            $context = ['request' => $locked->request_number, 'order' => $order->order_number, 'total' => (int) $order->total, 'payment_type' => $event->payment_type, 'via' => $result['via']];

            if (! $result['ok']) {
                $locked->forceFill(['auto_refund_status' => 'failed', 'auto_refund_error' => mb_substr($result['message'], 0, 190), 'auto_refund_at' => now()])->save();
                AuditLog::record('refund.auto_failed', $order, [...$context, 'error' => $result['message']], $actor?->id);
                $this->notifier->toRoles(['store-admin'], new StaffEvent('refund_pending', 'cancellation', $locked->request_number, "Refund otomatis pesanan {$order->order_number} gagal. Kembalikan manual atau coba lagi", $result['message']), $actor);

                return 'failed';
            }

            // Diterima Midtrans = dana dikembalikan (sama artinya dengan menandai manual). Tiba di pelanggan bergantung metode dan bank.
            $locked->forceFill(['status' => CancellationRequest::REFUNDED, 'refunded_at' => now(), 'auto_refund_status' => 'done', 'auto_refund_error' => null, 'auto_refund_at' => now()])->save();
            $order->forceFill(['status' => Order::REFUNDED, 'payment_status' => 'refunded'])->save();
            $order->histories()->create(['status_type' => 'payment', 'from_status' => 'refund_pending', 'to_status' => 'refunded', 'source' => 'midtrans_refund', 'actor_user_id' => $actor?->id, 'reason' => "Refund otomatis {$result['via']}"]);
            $order->histories()->create(['status_type' => 'order', 'from_status' => Order::CANCELLED, 'to_status' => Order::REFUNDED, 'source' => 'midtrans_refund', 'actor_user_id' => $actor?->id]);
            AuditLog::record('refund.auto_done', $order, $context, $actor?->id);
            $this->customers->cancellation($locked, 'refunded');

            return 'done';
        });
    }
}
