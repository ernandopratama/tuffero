<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'title_en', 'keywords', 'answer_id', 'answer_en', 'sort_order', 'is_active', 'is_suggested'])]
class ChatFaq extends Model
{
    public const MAX_KEYWORDS = 20;

    public const MIN_KEYWORD_LENGTH = 3;

    public const MAX_KEYWORD_LENGTH = 40;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_suggested' => 'boolean'];
    }

    /**
     * Kata kunci dipisah koma atau baris baru, huruf kecil, tanpa duplikat.
     *
     * @return list<string>
     */
    public static function parseKeywords(string $raw): array
    {
        $parts = preg_split('/[,\n]+/', mb_strtolower($raw)) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $parts), fn (string $word) => $word !== '')));
    }

    /** @return list<string> */
    public function keywordList(): array
    {
        return self::parseKeywords($this->keywords);
    }
}
