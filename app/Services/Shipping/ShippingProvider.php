<?php

namespace App\Services\Shipping;

/**
 * Sumber tarif ongkir (D-020). Biteship menjadi implementasi berikutnya; pemanggil tidak perlu berubah.
 */
interface ShippingProvider
{
    /**
     * @param  array<string, string>  $address  Alamat tujuan yang sudah divalidasi.
     * @return list<array{courier: string, service: string, label: string, fee: int, etd?: string, etd_unit?: string}>
     */
    public function rates(array $address, int $weightGram): array;
}
