<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu butir Syarat dan Ketentuan atau Kebijakan Privasi toko (kolom `type`, D-139), dua bahasa. Dikelola lewat TermsService;
 * versi S&K di setting `refund.terms_version`, versi Privasi di `privacy.version`.
 */
#[Fillable(['type', 'sort_order', 'title_id', 'title_en', 'body_id', 'body_en', 'is_published', 'updated_by_user_id'])]
class TermsSection extends Model
{
    use HasPublicId;

    public const TERMS = 'terms';

    public const PRIVACY = 'privacy';

    public const TYPES = [self::TERMS, self::PRIVACY];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    /**
     * @param  Builder<TermsSection>  $query
     * @return Builder<TermsSection>
     */
    public function scopePublished(Builder $query, string $type = self::TERMS): Builder
    {
        return $query->where('type', $type)->where('is_published', true)->orderBy('sort_order')->orderBy('id');
    }
}
