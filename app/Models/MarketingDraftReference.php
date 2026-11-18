<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Foto acuan dari tim untuk storyboard video. Berkasnya di disk privat `local`, hanya terbuka lewat rute admin yang dijaga izin. */
#[Fillable(['path', 'uploaded_by_user_id'])]
class MarketingDraftReference extends Model
{
    use HasPublicId;

    /** @return BelongsTo<MarketingDraft, $this> */
    public function draft(): BelongsTo
    {
        return $this->belongsTo(MarketingDraft::class, 'marketing_draft_id');
    }
}
