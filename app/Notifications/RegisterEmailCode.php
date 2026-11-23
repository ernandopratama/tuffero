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
        // Tampilan HTML yang sama dengan kode OTP login staf (resources/views/emails), plus versi teks biasa.
        return (new MailMessage)
            ->subject('Kode verifikasi pendaftaran Tuffero')
            ->view(['emails.mfa-code', 'emails.mfa-code-text'], [
                'name' => null,
                'code' => $this->code,
                'minutes' => $this->minutes,
                'label' => 'Kode verifikasi pendaftaran',
                'intro' => 'Masukkan kode di atas untuk memverifikasi email dan menyelesaikan pendaftaran akun Tuffero Anda.',
                'noticeTitle' => 'Bukan Anda yang mendaftar?',
                'notice' => 'Abaikan email ini. Jangan berikan kode ini kepada siapa pun, termasuk yang mengaku dari Tuffero.',
            ]);
    }
}
