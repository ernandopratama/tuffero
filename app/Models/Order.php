<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property array<string, string> $shipping_address
 */
#[Fillable([
    'user_id', 'public_ref', 'order_number', 'access_token_hash', 'idempotency_key', 'warehouse_id',
    'customer_name', 'customer_email', 'customer_phone', 'shipping_address', 'shipping_courier', 'shipping_service',
    'status', 'payment_status', 'shipping_status', 'subtotal', 'discount_total', 'shipping_fee', 'total', 'expires_at',
])]
#[Hidden(['access_token_hash', 'idempotency_key'])]
class Order extends Model
{
    public const PENDING_PAYMENT = 'pending_payment';

    public const EXPIRED = 'expired';

    public const PAID = 'paid';

    public const PROCESSING = 'processing';

    public const SHIPPED = 'shipped';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const REFUNDED = 'refunded';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shipping_address' => 'array',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return HasOne<Shipment, $this> */
    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }

    /** @return HasMany<OrderCancellationRequest, $this> */
    public function cancellationRequests(): HasMany
    {
        return $this->hasMany(OrderCancellationRequest::class);
    }

    /** @return MorphMany<AdminComment, $this> */
    public function comments(): MorphMany
    {
        return $this->morphMany(AdminComment::class, 'commentable');
    }

    /** Pembatalan hanya sebelum pesanan diserahkan ke kurir (D-043). */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, [self::PENDING_PAYMENT, self::PAID, self::PROCESSING], true);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<StockReservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function histories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }
}
