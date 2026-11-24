<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demografi followers per platform dan bulan (D-151 butir 5), disalin manual dari insight akun.
 * `data`: `age`, `gender`, dan `locations`, masing-masing daftar `{label, percent}`. `month` selalu tanggal 1 (`Y-m-d`).
 *
 * @property string $platform
 * @property string $month
 * @property bool $is_test
 * @property array<string, list<array{label: string, percent: float|int}>> $data
 */
#[Fillable(['platform', 'month', 'is_test', 'data'])]
class AccountAudienceSnapshot extends Model
{
    use HasPublicId;

    public const GROUPS = ['age', 'gender', 'locations'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_test' => 'boolean', 'data' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
