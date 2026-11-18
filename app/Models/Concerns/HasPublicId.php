<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * ID publik acak (ULID huruf kecil) di kolom `public_id` untuk URL dan API admin (D-143). `id` angka tetap kunci
 * internal dan foreign key. Binding rute memakai `public_id`; ID angka atau string yang bukan ULID dijawab 404.
 * Bukan token akses: izin, kepemilikan, dan MFA tetap diperiksa seperti biasa.
 */
trait HasPublicId
{
    use HasUlids;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
