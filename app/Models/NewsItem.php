<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Berita, update, atau promo manual di popup beranda toko (D-085). Dikelola Marketing dan Super Admin.
 *
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $ends_at
 */
#[Fillable(['kind', 'title', 'title_en', 'body', 'body_en', 'cta_label', 'cta_label_en', 'cta_url', 'starts_at', 'ends_at', 'sort_order', 'is_published'])]
class NewsItem extends Model
{
    use SoftDeletes;

    /** Jenis = lencana kecil di kartu popup. */
    public const KINDS = ['news' => 'Berita', 'update' => 'Update', 'promo' => 'Promo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_published' => 'boolean', 'sort_order' => 'integer'];
    }

    /** Tayang: siap tayang dan di dalam jendela waktunya. */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('is_published', true)
            ->where(fn (Builder $w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? '/storage/'.$this->image_path : null;
    }

    /** Teks menurut bahasa halaman; Inggris jatuh ke Indonesia bila belum diisi. */
    public function textFor(string $field, string $locale): ?string
    {
        return ($locale === 'en' && $this->{$field.'_en'}) ? $this->{$field.'_en'} : $this->{$field};
    }
}
