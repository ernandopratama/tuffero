<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarbonInterface $occurred_at
 */
#[Fillable(['shipment_id', 'event_key', 'status', 'note', 'occurred_at'])]
class ShipmentEvent extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
