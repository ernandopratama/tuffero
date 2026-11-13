<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Profil milik akun yang sedang masuk. Hanya bisa mengubah akun sendiri; role, status, dan MFA tetap di tangan Super Admin.
 */
class ProfileService
{
    public function __construct(private ImageService $images) {}

    /** @return array<string, mixed> */
    public function show(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'phone' => $user->phone,
            'phone_verified' => $user->phone_verified_at !== null,
            'avatar_url' => $user->avatarUrl(),
            'role' => $user->getRoleNames()->first(),
            'mfa' => [
                'enabled' => $user->mfa_totp_confirmed_at !== null,
                'required' => (bool) $user->mfa_required,
                'recovery_codes_left' => count($user->mfa_recovery_codes ?? []),
            ],
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    /** @param array{name?: string, email?: string, phone?: string|null} $data */
    public function update(User $user, array $data): User
    {
        $changes = [];

        foreach (['name', 'email', 'phone'] as $field) {
            if (! array_key_exists($field, $data) || $user->{$field} === $data[$field]) {
                continue;
            }

            // Nomor HP tidak ditulis utuh ke Audit Log: hanya empat digit terakhir.
            $mask = fn (?string $phone) => $phone ? '+62••••'.substr($phone, -4) : null;
            $changes[$field] = $field === 'phone'
                ? ['old' => $mask($user->phone), 'new' => $mask($data[$field])]
                : ['old' => $user->{$field}, 'new' => $data[$field]];
            $user->forceFill([$field => $data[$field]]);

            // Nomor baru belum terverifikasi.
            if ($field === 'phone') {
                $user->forceFill(['phone_verified_at' => null]);
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

    /** Dipanggil setelah kode OTP terbukti benar. Null menghapus username (hanya email yang bisa dipakai masuk). */
    public function setUsername(User $user, ?string $username): User
    {
        if ($user->username !== $username) {
            DB::transaction(function () use ($user, $username) {
                $old = $user->username;
                $user->forceFill(['username' => $username])->save();
                AuditLog::record('profile.username_changed', $user, ['username' => ['old' => $old, 'new' => $username]], $user->id);
            });
        }

        return $user;
    }

    /** Foto profil: JPG/JPEG/PNG, dipotong persegi 512 px, disimpan WebP. Berkas lama dihapus. */
    public function setAvatar(User $user, UploadedFile $file): User
    {
        $old = $user->avatar_path;
        $path = $this->images->storeWebp($file, "avatars/{$user->id}", 512, 85, true);

        DB::transaction(function () use ($user, $path) {
            $user->forceFill(['avatar_path' => $path])->save();
            AuditLog::record('profile.avatar_changed', $user, [], $user->id);
        });

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return $user;
    }

    public function removeAvatar(User $user): User
    {
        $old = $user->avatar_path;

        if ($old) {
            DB::transaction(function () use ($user) {
                $user->forceFill(['avatar_path' => null])->save();
                AuditLog::record('profile.avatar_removed', $user, [], $user->id);
            });
            Storage::disk('public')->delete($old);
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
