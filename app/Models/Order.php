<?php

namespace App\Models;

use App\Http\Requests\Store\CheckoutRequest;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

/**
 * @property array<string, string> $shipping_address
 * @property-read Shipment|null $shipment
 */
#[Fillable([
    'user_id', 'public_ref', 'order_number', 'access_token_hash', 'idempotency_key', 'warehouse_id',
    'customer_name', 'customer_email', 'customer_phone', 'shipping_address', 'shipping_courier', 'shipping_service',
    'status', 'payment_status', 'shipping_status', 'subtotal', 'discount_total', 'voucher_code', 'voucher_code_2', 'shipping_fee', 'total', 'expires_at',
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

    /**
     * Pesanan kedaluwarsa atau dibatalkan sebelum bayar yang kemudian menerima pembayaran (settlement atau capture). OrderStatusService::markPaid
     * tidak mengubahnya dan staf toko diberi tahu; dana harus dikembalikan manual. Pesanan yang sudah masuk alur refund berstatus
     * refund_pending atau refunded, jadi tidak ikut terhitung.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeLatePaymentClosed(Builder $query): Builder
    {
        return $query->whereIn('status', [self::EXPIRED, self::CANCELLED])
            ->whereIn('payment_status', ['expired', 'cancelled'])
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('payment_events')
                ->whereColumn('payment_events.order_id', 'orders.id')
                ->whereIn('payment_events.transaction_status', ['settlement', 'capture']));
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

    /** Pembatalan hanya sebelum pesanan diserahkan ke kurir (D-043). */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, [self::PENDING_PAYMENT, self::PAID, self::PROCESSING], true);
    }

    /**
     * Pesanan hanya ditemukan bila nomor dan kontak (email atau HP) cocok. Dipakai Cek Pesanan dan chat (AUTH-14).
     */
    public static function findForContact(string $orderNumber, string $contact): ?self
    {
        $contact = trim($contact);
        $order = self::where('order_number', strtoupper(trim($orderNumber)))->first();

        $matches = $order && (str_contains($contact, '@')
            ? hash_equals($order->customer_email, strtolower($contact))
            : hash_equals($order->customer_phone, CheckoutRequest::normalizePhone($contact)));

        return $matches ? $order : null;
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
