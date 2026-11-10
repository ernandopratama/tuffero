<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Perangkat (browser dan sistem operasi) yang pernah dipakai staf masuk. Dipakai untuk peringatan perangkat baru. */
class LoginDevice extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }
}
