<?php

namespace App\Services;

use App\Mail\CustomerOrderUpdate;
use App\Models\AuditLog;
use App\Models\CustomerNotification;
use App\Models\Order;
use App\Models\OrderCancellationRequest as CancellationRequest;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pesan otomatis ke pelanggan saat pesanan dibatalkan atau refund berubah status. Isi hanya nomor pesanan, nomor pengajuan,
 * status, dan langkah berikutnya: tanpa alamat, data kartu, token, atau alasan yang ditulis staf.
 *
 * - Dikirim setelah transaksi pemanggil commit (transaksi yang dibatalkan tidak mengirim apa pun).
 * - Galat pengiriman ditangkap dan dicatat tanpa data pribadi; pembatalan atau refund tetap berhasil.
 * - Satu pesan per kejadian dan saluran: kunci unik `customer_notifications (event_key, channel)`.
 * - Email lewat antrean; statusnya mengikuti hasil sebenarnya (`sent` saat server email menerima, `failed` setelah semua percobaan).
 *   WhatsApp hanya bila saklar Pengaturan menyala (bawaan mati sampai akun Meta aktif).
 * - CS yang memegang izin `emails.send` bisa mengirim ulang email lewat `resend` (tercatat di audit log).
 */
class CustomerNotifier
{
    public function __construct(private CustomerWhatsApp $whatsapp) {}

    /** Pelanggan membatalkan sendiri pesanan yang belum dibayar (D-138). */
    public function orderCancelled(Order $order): void
    {
        $this->send($order, "order:{$order->order_number}:cancelled", 'cancelled_unpaid');
    }

    /** Dana yang masuk setelah pesanan ditutup sudah dikembalikan Admin Toko (D-098). */
    public function latePaymentRefunded(Order $order): void
    {
        $this->send($order, "order:{$order->order_number}:late_refunded", 'late_payment_refunded');
    }

    /** @param  'submitted'|'approved'|'rejected'|'refunded'  $stage */
    public function cancellation(CancellationRequest $request, string $stage): void
    {
        $order = $request->order;
        $message = match ($stage) {
            'approved' => $order->payment_status === 'refund_pending' ? 'cancellation_refund_pending' : 'cancellation_approved',
            default => "cancellation_{$stage}",
        };

        $this->send($order, "cancellation:{$request->request_number}:{$stage}", $message, $request->request_number);
    }

    /**
     * @param  'submitted'|'approved'|'rejected'|'refunded'|'evidence_insufficient'  $stage
     * @param  int|null  $sequence  untuk kejadian yang bisa berulang (video kurang lengkap): urutan ke berapa
     */
    public function refund(Refund $refund, string $stage, ?int $sequence = null): void
    {
        $message = $stage === 'refunded' ? 'refund_paid_'.($refund->refund_method ?: 'manual_transfer') : "refund_{$stage}";

        $this->send($refund->order, "refund:{$refund->refund_number}:{$stage}".($sequence ? ":{$sequence}" : ''), $message, $refund->refund_number);
    }

    /**
     * Kirim ulang email oleh staf. Sengaja melewati pencegah pesan ganda: CS yang memutuskan (misalnya pelanggan mengaku belum menerima).
     *
     * @throws ValidationException bila bukan email, belum boleh dikirim ulang, atau pelanggan tidak punya email sah
     */
    public function resend(CustomerNotification $notification, User $actor): void
    {
        if (! $notification->canResend()) {
            throw ValidationException::withMessages(['notification' => 'Pesan ini belum bisa dikirim ulang.']);
        }

        $order = $notification->order;
        CustomerNotification::whereKey($notification->id)->update(['status' => CustomerNotification::PENDING, 'error' => null]);

        $status = $this->mail($notification, $order);
        $this->settle($notification, $status);

        if ($status === CustomerNotification::SKIPPED) {
            throw ValidationException::withMessages(['notification' => 'Pelanggan tidak punya alamat email yang sah.']);
        }

        AuditLog::record('customer_notification.resent', $order, ['event' => $notification->event_key, 'message' => $notification->message], $actor->id);
    }

    private function send(Order $order, string $key, string $message, ?string $reference = null): void
    {
        DB::afterCommit(function () use ($order, $key, $message, $reference) {
            $this->deliver('mail', $order, $key, $message, $reference, fn (CustomerNotification $n) => $this->mail($n, $order));

            if (CustomerWhatsApp::enabled()) {
                $this->deliver('whatsapp', $order, $key, $message, $reference, fn (CustomerNotification $n) => $this->whatsapp->send($n));
            }
        });
    }

    /** @param  callable(CustomerNotification): string  $send  mengembalikan `queued` atau `skipped` */
    private function deliver(string $channel, Order $order, string $key, string $message, ?string $reference, callable $send): void
    {
        try {
            $claimed = DB::table('customer_notifications')->insertOrIgnore([
                'public_id' => strtolower((string) Str::ulid()), 'order_id' => $order->id, 'event_key' => $key, 'channel' => $channel,
                'message' => $message, 'reference' => $reference, 'status' => CustomerNotification::PENDING, 'created_at' => now(), 'updated_at' => now(),
            ]);

            if ($claimed === 0) {
                return; // Kejadian ini sudah pernah dikirim lewat saluran ini.
            }

            $notification = CustomerNotification::where('event_key', $key)->where('channel', $channel)->firstOrFail();
            $this->settle($notification, $send($notification));
        } catch (Throwable $e) {
            // Tanpa pesan galat: bisa memuat alamat email atau nomor penerima.
            Log::warning('Customer notification failed', ['event' => $key, 'channel' => $channel, 'error' => $e::class]);
            rescue(fn () => CustomerNotification::where('event_key', $key)->where('channel', $channel)->update(['status' => CustomerNotification::FAILED, 'error' => mb_substr(class_basename($e), 0, 60)]), report: false);
        }
    }

    /**
     * Dengan antrean `sync` email sudah terkirim (`sent`) sebelum baris ini sampai di sini; status hanya diubah bila masih `pending`.
     */
    private function settle(CustomerNotification $notification, string $status): void
    {
        CustomerNotification::whereKey($notification->id)->where('status', CustomerNotification::PENDING)->update(['status' => $status]);
    }

    private function mail(CustomerNotification $notification, Order $order): string
    {
        $email = (string) $order->customer_email;

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            Log::info('Customer notification skipped: no valid email', ['order' => $order->order_number]);

            return CustomerNotification::SKIPPED;
        }

        Mail::to($email)->locale(self::locale($order))->queue(new CustomerOrderUpdate($notification->message, $order->order_number, $notification->reference, $notification->id));

        return CustomerNotification::QUEUED;
    }

    public static function locale(Order $order): string
    {
        return in_array($order->locale, ['id', 'en'], true) ? $order->locale : 'id';
    }
}
