<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
// status, penulis, dan kolom keputusan tidak fillable: hanya berubah lewat MarketingDraftService.
#[Fillable(['type', 'platform', 'title', 'body', 'sources', 'source_period', 'scheduled_on'])]
class MarketingDraft extends Model
{
    use SoftDeletes;

    public const DRAFT = 'draft';

    public const IN_REVIEW = 'in_review';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const POSTED = 'posted';

    public const MANUAL = 'manual';

    public const AI = 'ai';

    public const HERMES = 'hermes';

    public const TYPES = ['video_script' => 'Skrip video', 'caption' => 'Caption', 'calendar_item' => 'Kalender konten', 'trend_report' => 'Riset tren'];

    public const PLATFORMS = ['instagram' => 'Instagram', 'tiktok' => 'TikTok'];

    /** Draf hanya boleh disunting saat belum diajukan atau setelah ditolak. */
    public const EDITABLE = [self::DRAFT, self::REJECTED];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['scheduled_on' => 'date', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'posted_at' => 'datetime'];
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
