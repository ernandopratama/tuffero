<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Draf konten AI Marketing (D-062, D-079). Marketing menyusun dan mengajukan, Pemilik atau Super Admin memutuskan,
 * publikasi tetap manual (BR-19): `posted` hanya penanda bahwa tim sudah memposting sendiri.
 *
 * @property CarbonInterface|null $scheduled_on
 * @property CarbonInterface|null $submitted_at
 * @property CarbonInterface|null $reviewed_at
 * @property CarbonInterface|null $posted_at
 */
// status, penulis, kolom keputusan, dan storyboard tidak fillable: hanya berubah lewat MarketingDraftService dan StoryboardGenerator.
#[Fillable(['type', 'platform', 'title', 'body', 'sources', 'source_period', 'scheduled_on', 'duration_seconds', 'music_note', 'visual_mode'])]
class MarketingDraft extends Model
{
    use HasPublicId;
    use SoftDeletes;

    public const DRAFT = 'draft';

    public const IN_REVIEW = 'in_review';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const POSTED = 'posted';

    public const MANUAL = 'manual';

    public const AI = 'ai';

    public const HERMES = 'hermes';

    /** Semua jenis yang pernah ada (label untuk data lama). */
    public const TYPES = ['video_script' => 'Skrip video', 'caption' => 'Caption', 'calendar_item' => 'Kalender konten', 'trend_report' => 'Riset tren'];

    /**
     * Jenis yang boleh dibuat sekarang. Skrip video dan kalender konten digabung menjadi satu (rencana video, bertanggal);
     * caption pindah ke Generator Caption (tabel caption_generations), bukan draf yang diajukan.
     */
    public const CREATABLE_TYPES = ['video_script', 'trend_report'];

    public const PLATFORMS = ['instagram' => 'Instagram', 'tiktok' => 'TikTok'];

    /** Draf hanya boleh disunting saat belum diajukan atau setelah ditolak. */
    public const EDITABLE = [self::DRAFT, self::REJECTED];

    /** Status storyboard otomatis untuk draf skrip video. */
    public const STORYBOARD_PENDING = 'pending';

    public const STORYBOARD_READY = 'ready';

    public const STORYBOARD_FAILED = 'failed';

    /** Mode AI penuh menunggu foto acuan cukup; AI belum dipanggil. */
    public const STORYBOARD_WAITING = 'waiting';

    /** Sumber visual video: foto/rekaman tim (bawaan) atau dibuat AI sepenuhnya dari foto acuan produk asli. */
    public const VISUAL_MODES = ['team' => 'Foto/rekaman tim', 'ai' => 'Dibuat AI sepenuhnya'];

    /** Mode AI penuh wajib minimal 2 foto acuan supaya tampilan produk mengikuti produk asli. */
    public const MIN_AI_REFERENCES = 2;

    /** Pilihan durasi video (detik); kosong berarti 30 detik. */
    public const DURATIONS = [15, 30, 60];

    public const MAX_REFERENCES = 6;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['scheduled_on' => 'date', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'posted_at' => 'datetime', 'storyboard' => 'array', 'storyboard_at' => 'datetime'];
    }

    /** @return HasMany<MarketingDraftReference, $this> */
    public function references(): HasMany
    {
        return $this->hasMany(MarketingDraftReference::class)->orderBy('id');
    }

    /** @return HasMany<MarketingDraftNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(MarketingDraftNote::class)->orderBy('id');
    }

    /** @return HasMany<MarketingDraftMetric, $this> */
    public function metrics(): HasMany
    {
        return $this->hasMany(MarketingDraftMetric::class);
    }

    /** Draf ditempel manual ke Instagram/TikTok yang tidak membaca Markdown, jadi tandanya dibuang saat disimpan. */
    public static function plain(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = preg_replace(['/\[([^\]\n]+)\]\((https?:\/\/[^)\s]+)\)/', '/\*\*|__/', '/^#{1,6}\s+/m', '/^(\s*)\*\s+/m'], ['$1 ($2)', '', '', '$1- '], $text);

        return $text;
    }

    /** @return Attribute<string, string|null> */
    protected function title(): Attribute
    {
        return Attribute::set(fn (?string $value) => self::plain($value));
    }

    /** @return Attribute<string, string|null> */
    protected function body(): Attribute
    {
        return Attribute::set(fn (?string $value) => self::plain($value));
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
