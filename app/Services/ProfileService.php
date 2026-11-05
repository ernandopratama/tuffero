<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Profil milik akun yang sedang masuk. Hanya bisa mengubah akun sendiri; role, status, dan MFA tetap di tangan Super Admin.
 */
class ProfileService
{
    /** @return array<string, mixed> */
    public function show(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getRoleNames()->first(),
            'mfa' => [
                'enabled' => $user->mfa_totp_confirmed_at !== null,
                'required' => (bool) $user->mfa_required,
                'recovery_codes_left' => count($user->mfa_recovery_codes ?? []),
            ],
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    /** @param array{name: string, email: string} $data */
    public function update(User $user, array $data): User
    {
        $changes = [];

        foreach (['name', 'email'] as $field) {
            if ($user->{$field} !== $data[$field]) {
                $changes[$field] = ['old' => $user->{$field}, 'new' => $data[$field]];
                $user->forceFill([$field => $data[$field]]);
            }
        }

        if ($changes) {
            DB::transaction(function () use ($user, $changes) {
                $user->save();
                AuditLog::record('profile.updated', $user, $changes, $user->id);
            });
        }

        return $user;
    }

    /** Sesi di perangkat lain berakhir; sesi yang dipakai sekarang tetap berjalan. */
    public function changePassword(User $user, string $password, string $currentSessionId): void
    {
        DB::transaction(function () use ($user, $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            AuditLog::record('profile.password_changed', $user, [], $user->id);
        });

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->where('id', '!=', $currentSessionId)->delete();
        }
    }
}
