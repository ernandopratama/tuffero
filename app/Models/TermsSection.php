<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Satu butir Syarat dan Ketentuan toko, dua bahasa. Dikelola lewat TermsService; versi di setting `refund.terms_version`. */
#[Fillable(['sort_order', 'title_id', 'title_en', 'body_id', 'body_en', 'is_published', 'updated_by_user_id'])]
class TermsSection extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    /**
     * @param  Builder<TermsSection>  $query
     * @return Builder<TermsSection>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)->orderBy('sort_order')->orderBy('id');
    }
}
