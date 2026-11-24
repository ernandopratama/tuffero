<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan metrik harian konten tayang (input manual tim marketing). Angka kumulatif sampai tanggal ukur,
 * disalin dari insight TikTok atau Instagram; server tidak pernah mengambil data dari platform.
 * `measured_on` sengaja tanpa cast tanggal: disimpan dan dibandingkan sebagai `Y-m-d` (indeks unik per hari).
 *
 * @property string $measured_on
 */
#[Fillable(['platform', 'post_url', 'measured_on', 'views', 'likes', 'shares', 'comments', 'saves', 'reach', 'new_followers'])]
class MarketingDraftMetric extends Model
{
    use HasPublicId;

    public const PLATFORMS = ['tiktok' => 'TikTok', 'instagram' => 'Instagram'];

    /** Angka wajib; `reach` dan `new_followers` boleh kosong karena tidak selalu tersedia per posting. */
    public const COUNTS = ['views', 'likes', 'shares', 'comments', 'saves'];

    public const OPTIONAL_COUNTS = ['reach', 'new_followers'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['views' => 'integer', 'likes' => 'integer', 'shares' => 'integer', 'comments' => 'integer', 'saves' => 'integer', 'reach' => 'integer', 'new_followers' => 'integer'];
    }

    /** @return BelongsTo<MarketingDraft, $this> */
    public function draft(): BelongsTo
    {
        return $this->belongsTo(MarketingDraft::class, 'marketing_draft_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
