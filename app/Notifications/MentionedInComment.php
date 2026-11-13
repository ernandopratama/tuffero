<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Staf ditandai dengan @ di catatan internal (D-045). Hanya lewat kanal database: tampil di lonceng dashboard.
 */
class MentionedInComment extends Notification
{
    /** @param  'order'|'voucher'|'content'|'video'|'refund'|'cancellation'  $kind */
    public function __construct(
        private readonly string $by,
        private readonly string $kind,
        private readonly string $label,
        private readonly string $body,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        $noun = ['order' => 'pesanan', 'voucher' => 'voucher', 'content' => 'konten promo', 'video' => 'video', 'refund' => 'refund', 'cancellation' => 'pembatalan'][$this->kind];

        return [
            'event' => 'mention',
            'by' => $this->by,
            'kind' => $this->kind,
            'label' => $this->label,
            'title' => "{$this->by} menyebut Anda di {$noun} {$this->label}",
            'excerpt' => Str::limit($this->body, 120),
        ];
    }
}
