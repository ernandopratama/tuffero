<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Pengajuan refund atau retur (D-031, D-097). Alur dan aturannya ada di RefundService.
 *
 * @property CarbonInterface $due_at
 * @property CarbonInterface|null $contacted_at
 * @property CarbonInterface|null $decided_at
 * @property CarbonInterface|null $refunded_at
 */
#[Hidden(['idempotency_key', 'ip_address', 'user_agent'])]
class Refund extends Model
{
    public const SUBMITTED = 'submitted';

    public const AWAITING_EVIDENCE = 'awaiting_evidence';

    public const UNDER_REVIEW = 'under_review';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const REFUNDED = 'refunded';

    public const CANCELLED = 'cancelled';

    /** Belum diputuskan: masih bisa ditolak atau dibatalkan. */
    public const OPEN = [self::SUBMITTED, self::AWAITING_EVIDENCE, self::UNDER_REVIEW];

    /** Dihitung sebagai jumlah barang yang sudah diklaim; yang ditolak atau dibatalkan dibebaskan lagi. */
    public const CLAIMING = [self::SUBMITTED, self::AWAITING_EVIDENCE, self::UNDER_REVIEW, self::APPROVED, self::REFUNDED];

    /** Jenis masalah. `return_unopened` adalah retur produk utuh (D-097); selebihnya klaim barang bermasalah (D-043). */
    public const REASONS = ['damaged', 'wrong_item', 'missing_item', 'not_fit_for_consumption', 'return_unopened', 'other'];

    /** Pilihan pelanggan: dana dikembalikan, kirim ulang barang, atau voucher pengganti. */
    public const CHOICES = ['refund', 'resend', 'voucher'];

    public const RETURN_REASON = 'return_unopened';

    /** Jenis masalah yang kesalahannya ada di Tuffero, sehingga ongkir yang dibayar pelanggan ikut dikembalikan bila Pemilik memilihnya. */
    public const SHIPPING_REASONS = ['wrong_item', 'missing_item'];

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'terms_accepted_at' => 'datetime',
            'due_at' => 'datetime',
            'contacted_at' => 'datetime',
            'decided_at' => 'datetime',
            'refunded_at' => 'datetime',
            'evidence_received_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** Lewat batas proses dan belum selesai (hanya penanda, tidak mengubah apa pun). */
    public function isOverdue(): bool
    {
        return in_array($this->status, [...self::OPEN, self::APPROVED], true) && $this->due_at->isPast();
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return MorphMany<AdminComment, $this> */
    public function comments(): MorphMany
    {
        return $this->morphMany(AdminComment::class, 'commentable');
    }

    /** @return MorphMany<FollowUp, $this> */
    public function followUps(): MorphMany
    {
        return $this->morphMany(FollowUp::class, 'followable');
    }

    /** @return MorphMany<CustomerEmail, $this> */
    public function customerEmails(): MorphMany
    {
        return $this->morphMany(CustomerEmail::class, 'emailable');
    }

    /** @return HasMany<RefundItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function histories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->where('status_type', 'refund');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
