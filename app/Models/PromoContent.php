<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['type', 'title', 'body', 'video_url', 'provider', 'external_id', 'sort_order', 'starts_at', 'ends_at', 'is_published'])]
class PromoContent extends Model
{
    use SoftDeletes;

    /** Hasil pengambilan thumbnail otomatis pada permintaan ini ('fetched', 'failed', 'unsupported'); tidak disimpan. */
    public ?string $thumbnailStatus = null;

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
            'sort_order' => 'integer',
        ];
    }

    /** Konten yang sedang tayang: siap tayang dan di dalam jendela waktunya. */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('is_published', true)
            ->where(fn (Builder $w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    /**
     * Video yang tampil di "Di Balik Layar" (Galeri): tayang dan sudah punya thumbnail.
     *
     * @return Collection<int, static>
     */
    public static function liveVideos(int $limit = 8): Collection
    {
        return static::query()->live()->where('type', 'video')->whereNotNull('image_path')
            ->orderBy('sort_order')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Alamat pemutar tersemat (D-059). Host tetap per platform dan ID divalidasi ketat; null berarti tautan keluar saja
     * (mis. tautan pendek TikTok tanpa ID). YouTube lewat domain `youtube-nocookie.com` agar tidak memasang cookie pelacak sebelum diputar.
     */
    public function embedUrl(): ?string
    {
        $id = (string) $this->external_id;

        return match (true) {
            $this->provider === 'youtube' && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1 => "https://www.youtube-nocookie.com/embed/{$id}?autoplay=1&playsinline=1&rel=0",
            $this->provider === 'tiktok' && preg_match('/^\\d{5,25}$/', $id) === 1 => "https://www.tiktok.com/player/v1/{$id}?autoplay=1&rel=0&music_info=0&description=0",
            $this->provider === 'instagram' && preg_match('/^[A-Za-z0-9_-]{5,20}$/', $id) === 1 => "https://www.instagram.com/p/{$id}/embed/",
            default => null,
        };
    }

    /** @return MorphMany<AdminComment, $this> */
    public function comments(): MorphMany
    {
        return $this->morphMany(AdminComment::class, 'commentable');
    }
}
