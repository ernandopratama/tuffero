<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['actor_user_id', 'action', 'auditable_type', 'auditable_id', 'changes', 'ip_address', 'user_agent'])]
class AuditLog extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    /**
     * @param  array<string, mixed>  $changes  Tanpa password atau secret.
     */
    public static function record(string $action, Model $target, array $changes = [], ?int $actorId = null): self
    {
        return self::create([
            'actor_user_id' => $actorId,
            'action' => $action,
            'auditable_type' => $target::class,
            'auditable_id' => $target->getKey(),
            'changes' => $changes ?: null,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 255) ?: null,
        ]);
    }

    /**
     * Menyimpan perubahan model dan mencatat nilai lama dan baru. Tanpa catatan bila tidak ada yang berubah.
     *
     * @param  array<string, mixed>  $data
     */
    public static function saveWithAudit(Model $model, array $data, string $action, User $actor): void
    {
        $model->fill($data);

        $changes = [];
        foreach ($model->getDirty() as $field => $new) {
            $changes[$field] = ['old' => $model->getOriginal($field), 'new' => $model->getAttribute($field)];
        }

        $model->save();

        if ($changes) {
            self::record($action, $model, $changes, $actor->id);
        }
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    /**
     * Data sasaran log; dipakai untuk menampilkan ID publiknya (D-143). Kosong bila datanya sudah dihapus.
     *
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
