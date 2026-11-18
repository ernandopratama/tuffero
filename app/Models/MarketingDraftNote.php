<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Catatan tim untuk AI pada draf video. Setiap catatan baru memicu penyusunan ulang storyboard. */
#[Fillable(['body', 'user_id'])]
class MarketingDraftNote extends Model
{
    use HasPublicId;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
