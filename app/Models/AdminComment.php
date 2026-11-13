<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan internal staf (D-045): tidak terlihat pelanggan dan tidak bisa diedit atau dihapus.
 */
#[Fillable(['user_id', 'body', 'mentions', 'visibility'])]
class AdminComment extends Model
{
    public const UPDATED_AT = null;

    public const STAFF = 'staff';

    /** Hanya pemegang izin `comments.cs` (Customer Service dan Super Admin) yang bisa membaca dan menulis. */
    public const CS = 'cs';

    public const CS_PERMISSION = 'comments.cs';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['mentions' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
