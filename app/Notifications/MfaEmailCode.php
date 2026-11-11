<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Kode OTP email untuk langkah kedua login staf. Dikirim langsung (tanpa antrean) karena pengguna sedang menunggu kodenya.
 */
class MfaEmailCode extends Notification
{
    public function __construct(public readonly string $code, public readonly int $minutes) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Tampilan HTML buatan sendiri (resources/views/emails) dan versi teks biasa untuk klien email yang tidak menampilkan HTML.
        return (new MailMessage)
            ->subject('Kode verifikasi login Tuffero')
            ->view(['emails.mfa-code', 'emails.mfa-code-text'], ['name' => $notifiable->name, 'code' => $this->code, 'minutes' => $this->minutes]);
    }
}
