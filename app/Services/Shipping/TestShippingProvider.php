<?php

namespace App\Services\Shipping;

/**
 * Tarif contoh untuk lokal dan test. Bukan harga kurir sungguhan; AppServiceProvider tidak memasangnya di environment lain.
 */
class TestShippingProvider implements ShippingProvider
{
    public function rates(array $address, int $weightGram): array
    {
        $kilograms = (int) max(1, ceil($weightGram / 1000));

        return [
            ['courier' => 'jnt', 'service' => 'ez', 'label' => 'J&T EZ (tarif uji)', 'fee' => 12000 * $kilograms, 'etd' => '2 - 3', 'etd_unit' => 'days'],
            ['courier' => 'sicepat', 'service' => 'reg', 'label' => 'SiCepat REG (tarif uji)', 'fee' => 11000 * $kilograms, 'etd' => '1 - 2', 'etd_unit' => 'days'],
        ];
    }
}
