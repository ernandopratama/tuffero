<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat Generator Caption: brief dari tim dan tiga variasi caption dari AI. Bukan draf: tidak diajukan atau disetujui,
 * Marketing cukup menyalinnya.
 */
#[Fillable(['user_id', 'platform', 'tone', 'brief', 'captions'])]
class CaptionGeneration extends Model
{
    use HasPublicId;

    public const TONES = ['santai' => 'Santai', 'hangat' => 'Hangat', 'elegan' => 'Elegan', 'ceria' => 'Ceria'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['captions' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
