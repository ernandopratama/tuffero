<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak pesan otomatis ke pelanggan (pembatalan dan refund): satu baris per kejadian dan saluran. Tanpa alamat, nomor, atau isi pesan.
 *
 * @property-read Order $order
 */
#[Fillable(['order_id', 'event_key', 'channel', 'message', 'reference', 'status', 'error'])]
class CustomerNotification extends Model
{
    use HasPublicId;

    public const PENDING = 'pending';

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    /** Antrean yang diam lebih lama dari ini dianggap macet (worker mati), jadi boleh dikirim ulang. */
    private const STUCK_MINUTES = 15;

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Pesan milik refund, pengajuan pembatalan, atau pesanan: dicocokkan lewat awalan kunci kejadian. */
    public function scopeForTarget(Builder $query, Order|Refund|OrderCancellationRequest $target): void
    {
        match (true) {
            $target instanceof Refund => $query->where('event_key', 'like', "refund:{$target->refund_number}:%"),
            $target instanceof OrderCancellationRequest => $query->where('event_key', 'like', "cancellation:{$target->request_number}:%"),
            default => $query->where('order_id', $target->id),
        };
    }

    /** Subjek dalam bahasa Indonesia untuk staf. */
    public function subject(): string
    {
        return trans("customer_mail.messages.{$this->message}.subject", ['order' => $this->order->order_number, 'ref' => (string) $this->reference], 'id');
    }

    /** Hanya email. Terkirim atau gagal boleh dikirim ulang oleh CS; yang menunggu hanya bila macet. */
    public function canResend(): bool
    {
        return $this->channel === 'mail' && match ($this->status) {
            self::SENT, self::FAILED => true,
            self::PENDING, self::QUEUED => $this->updated_at->lte(now()->subMinutes(self::STUCK_MINUTES)),
            default => false,
        };
    }
}
