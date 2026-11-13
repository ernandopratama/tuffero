<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property CarbonInterface $due_at
 * @property CarbonInterface|null $validated_at
 * @property CarbonInterface|null $store_approved_at
 * @property CarbonInterface|null $owner_approved_at
 * @property CarbonInterface|null $rejected_at
 * @property CarbonInterface|null $refunded_at
 */
#[Fillable(['request_number', 'reason', 'channel', 'status', 'due_at'])]
class OrderCancellationRequest extends Model
{
    public const SUBMITTED = 'submitted';

    public const VALIDATED = 'validated';

    public const STORE_APPROVED = 'store_approved';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const REFUNDED = 'refunded';

    /** Status yang masih berjalan dan menahan pengiriman. */
    public const OPEN = [self::SUBMITTED, self::VALIDATED, self::STORE_APPROVED];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'validated_at' => 'datetime',
            'store_approved_at' => 'datetime',
            'owner_approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
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
}
