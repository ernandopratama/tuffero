<?php

namespace App\Services\Shipping;

use Illuminate\Validation\ValidationException;

/**
 * Dipasang di luar lokal selama Biteship belum tersambung, supaya checkout tidak bisa berjalan dengan tarif uji.
 */
class UnavailableShippingProvider implements ShippingProvider
{
    public function rates(array $address, int $weightGram): array
    {
        throw ValidationException::withMessages(['shipping' => 'shipping_unavailable']);
    }
}
