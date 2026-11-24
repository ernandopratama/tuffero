<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan performa akun media sosial (D-151 tahap 1, input manual): satu baris per platform dan periode (1, 7, atau 30 hari).
 * Angka disalin dari layar insight akun; server tidak mengambil data dari platform. Kolom kosong berarti tidak tersedia, bukan 0.
 * `period_end` dan `measured_on` sengaja tanpa cast tanggal: disimpan dan dibandingkan sebagai `Y-m-d`.
 *
 * @property string $platform
 * @property int $period_days
 * @property string $period_end
 * @property string $measured_on
 * @property bool $is_test
 * @property int $followers_total
 */
#[Fillable([
    'platform', 'period_days', 'period_end', 'measured_on', 'is_test', 'note',
    'followers_total', 'followers_gained', 'followers_lost', 'views', 'profile_visits',
    'accounts_reached', 'accounts_engaged', 'interactions', 'link_taps',
    'likes', 'comments', 'shares', 'total_viewers', 'unique_viewers', 'watch_time_seconds', 'avg_watch_seconds', 'account_likes_total',
])]
class SocialAccountMetric extends Model
{
    use HasPublicId;

    public const PLATFORMS = ['tiktok' => 'TikTok', 'instagram' => 'Instagram'];

    public const PERIODS = [1, 7, 30];

    /** Angka yang ada di kedua platform; hanya `followers_total` wajib. */
    public const COMMON = ['followers_total', 'followers_gained', 'followers_lost', 'views', 'profile_visits'];

    /** Angka khusus tiap platform. Platform lain wajib mengosongkannya. */
    public const ONLY = [
        'instagram' => ['accounts_reached', 'accounts_engaged', 'interactions', 'link_taps'],
        'tiktok' => ['likes', 'comments', 'shares', 'total_viewers', 'unique_viewers', 'watch_time_seconds', 'avg_watch_seconds', 'account_likes_total'],
    ];

    /** @return list<string> */
    public static function fieldsFor(string $platform): array
    {
        return [...self::COMMON, ...(self::ONLY[$platform] ?? [])];
    }

    /** @return list<string> */
    public static function allFields(): array
    {
        return [...self::COMMON, ...self::ONLY['instagram'], ...self::ONLY['tiktok']];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_test' => 'boolean', ...array_fill_keys(self::allFields(), 'integer')];
    }

    /** @param Builder<SocialAccountMetric> $query */
    public function scopeReal(Builder $query): void
    {
        $query->where('is_test', false);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
