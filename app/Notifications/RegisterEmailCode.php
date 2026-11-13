<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Kode verifikasi email saat pelanggan mendaftar. Dikirim langsung (tanpa antrean) karena pelanggan sedang menunggu kodenya.
 */
class RegisterEmailCode extends Notification
{
    public function __construct(public readonly string $code, public readonly int $minutes) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Kode verifikasi pendaftaran Tuffero')
            ->greeting('Halo,')
            ->line('Kode verifikasi pendaftaran akun Tuffero Anda:')
            ->line("**{$this->code}**")
            ->line("Berlaku {$this->minutes} menit dan hanya bisa dipakai sekali.")
            ->line('Bukan Anda yang mendaftar? Abaikan email ini. Jangan berikan kode ini kepada siapa pun, termasuk yang mengaku dari Tuffero.')
            ->salutation('Tuffero');
    }
}
