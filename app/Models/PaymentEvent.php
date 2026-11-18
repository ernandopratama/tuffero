<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed>|null $payload
 */
class PaymentEvent extends Model
{
    protected $fillable = ['order_id', 'transaction_id', 'transaction_status', 'fraud_status', 'payment_type', 'gross_amount', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
