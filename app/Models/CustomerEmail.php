<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak email CS ke pelanggan (D-108): tanpa isi email, alamat tujuan disamarkan.
 */
#[Fillable(['sent_by', 'to_masked', 'subject', 'status'])]
class CustomerEmail extends Model
{
    public const UPDATED_AT = null;

    public const SENT = 'sent';

    public const FAILED = 'failed';

    /** "budi@gmail.com" menjadi "b***@gmail.com". */
    public static function mask(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 1).'***@'.$domain;
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
