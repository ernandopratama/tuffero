<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['author_name', 'rating', 'body', 'source', 'reviewed_on', 'is_published'])]
class StoreReview extends Model
{
    use HasPublicId;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'reviewed_on' => 'date',
            'is_published' => 'boolean',
        ];
    }
}
