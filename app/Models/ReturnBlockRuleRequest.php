<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengajuan menyalakan blokir otomatis retur (D-133): diajukan Super Admin atau Admin Toko, diputuskan Pemilik.
 *
 * @property CarbonInterface|null $decided_at
 */
#[Fillable(['requested_by_user_id', 'note', 'status', 'pending_flag', 'decided_by_user_id', 'decision_note', 'decided_at'])]
class ReturnBlockRuleRequest extends Model
{
    use HasPublicId;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['pending_flag' => 'boolean', 'decided_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
