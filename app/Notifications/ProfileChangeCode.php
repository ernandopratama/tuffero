<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Kode konfirmasi saat staf mengubah data profil sensitif sendiri (saat ini: username). Dikirim langsung karena pengguna sedang menunggu.
 */
class ProfileChangeCode extends Notification
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
            ->subject('Kode konfirmasi perubahan akun Tuffero')
            ->greeting('Halo,')
            ->line('Kode untuk mengonfirmasi perubahan data akun Tuffero Anda:')
            ->line("**{$this->code}**")
            ->line("Berlaku {$this->minutes} menit dan hanya bisa dipakai sekali.")
            ->line('Bukan Anda yang meminta? Abaikan email ini dan segera ganti password Anda. Jangan berikan kode ini kepada siapa pun.')
            ->salutation('Tuffero');
    }
}
