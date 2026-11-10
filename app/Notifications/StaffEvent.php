<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Peristiwa operasional untuk staf, tampil di lonceng dashboard (kanal database saja).
 * `kind` menentukan halaman tujuan di frontend; `label` mengisi pencarian di halaman itu.
 */
class StaffEvent extends Notification
{
    /** @param  'order'|'cancellation'|'stock'|'security'  $kind */
    public function __construct(
        private readonly string $event,
        private readonly string $kind,
        private readonly string $label,
        private readonly string $title,
        private readonly string $excerpt = '',
    ) {}

    public function event(): string
    {
        return $this->event;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return ['event' => $this->event, 'kind' => $this->kind, 'label' => $this->label, 'title' => $this->title, 'excerpt' => Str::limit($this->excerpt, 120)];
    }
}
