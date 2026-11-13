<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'city', 'address', 'postal_code', 'latitude', 'longitude', 'phone', 'is_active'])]
class Warehouse extends Model
{
    /**
     * Kode pos asal untuk tarif ongkir: gudang aktif pertama yang kode posnya terisi; bila tidak ada, env BITESHIP_ORIGIN_POSTAL_CODE.
     * shortcut: tarif dihitung sebelum pesanan ada, jadi memakai gudang aktif pertama; ganti saat gudang kedua aktif.
     */
    public static function originPostalCode(): ?string
    {
        $postal = static::where('is_active', true)->whereNotNull('postal_code')->where('postal_code', '!=', '')->orderBy('id')->value('postal_code');

        return filled($postal) ? (string) $postal : (filled(config('services.biteship.origin_postal_code')) ? (string) config('services.biteship.origin_postal_code') : null);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
