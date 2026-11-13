<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Penanda tindak lanjut CS pada pesanan, refund, atau pembatalan. Hanya catatan pengingat, bukan tiket.
 *
 * @property CarbonInterface $due_on
 * @property CarbonInterface|null $done_at
 */
#[Fillable(['created_by', 'note', 'due_on'])]
class FollowUp extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['due_on' => 'date', 'done_at' => 'datetime'];
    }

    /** @return MorphTo<Model, $this> */
    public function followable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Belum selesai dan tanggalnya sudah lewat (hari ini belum dihitung lewat). */
    public function isOverdue(): bool
    {
        return $this->done_at === null && $this->due_on->lt(today());
    }
}
