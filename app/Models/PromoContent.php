<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['type', 'title', 'body', 'video_url', 'starts_at', 'ends_at', 'is_published'])]
class PromoContent extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_published' => 'boolean',
        ];
    }

    /** @return MorphMany<AdminComment, $this> */
    public function comments(): MorphMany
    {
        return $this->morphMany(AdminComment::class, 'commentable');
    }
}
