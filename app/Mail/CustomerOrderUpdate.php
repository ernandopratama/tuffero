<?php

namespace App\Mail;

use App\Models\CustomerNotification;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Throwable;

/**
 * Kabar pembatalan atau refund untuk pelanggan (CustomerNotifier). Hanya string yang diserialisasi ke antrean, bukan model,
 * supaya data pesanan lain tidak ikut tersimpan di tabel jobs. Teks di lang/{id,en}/customer_mail.php; bahasa dari Mail::locale().
 */
class CustomerOrderUpdate extends Mailable implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly string $messageKey, public readonly string $orderNumber, public readonly ?string $reference = null, public readonly ?int $notificationId = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->line('subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.customer-order-update',
            text: 'emails.customer-order-update-text',
            with: [
                'subjectLine' => $this->line('subject'),
                'status' => $this->line('status'),
                'next' => $this->line('next'),
                'orderNumber' => $this->orderNumber,
                'reference' => $this->reference,
                // Id baris jejak: pendengar MessageSent memakainya untuk menandai `sent`. Tautan umum, tanpa token atau data pesanan.
                'notificationId' => $this->notificationId,
                'checkUrl' => url('/cek-pesanan').'?lang='.app()->getLocale(),
                'whatsapp' => (string) Setting::where('key', 'contact.whatsapp_number')->value('value'),
            ],
        );
    }

    /** Dipanggil antrean setelah semua percobaan gagal. Hanya nama kelas galat yang disimpan. */
    public function failed(Throwable $e): void
    {
        if ($this->notificationId) {
            CustomerNotification::whereKey($this->notificationId)->update(['status' => CustomerNotification::FAILED, 'error' => mb_substr(class_basename($e), 0, 60)]);
        }
    }

    private function line(string $part): string
    {
        return __("customer_mail.messages.{$this->messageKey}.{$part}", ['order' => $this->orderNumber, 'ref' => (string) $this->reference]);
    }
}
