<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sesi login milik akun yang sedang masuk (D-089): daftar perangkat dan keluar dari perangkat lain.
 * Hanya berjalan bila sesi disimpan di database; driver lain tidak bisa diurutkan per pengguna.
 * ID sesi asli tidak pernah dikirim ke browser: yang dikirim adalah token turunan (HMAC) yang tidak bisa dipakai membajak sesi.
 */
class SessionService
{
    public function supported(): bool
    {
        return config('session.driver') === 'database';
    }

    /** @return list<array<string, mixed>> */
    public function list(User $user, string $currentId): array
    {
        if (! $this->supported()) {
            return [];
        }

        return $this->table()->where('user_id', $user->id)->orderByDesc('last_activity')->get()
            ->map(function (object $row) use ($currentId) {
                $device = $this->describe((string) $row->user_agent);

                return [
                    'token' => $this->token($row->id),
                    'current' => $row->id === $currentId,
                    'browser' => $device['browser'],
                    'os' => $device['os'],
                    'mobile' => $device['mobile'],
                    'ip' => $row->ip_address,
                    'last_active' => date(DATE_ATOM, (int) $row->last_activity),
                ];
            })->values()->all();
    }

    /** Keluarkan satu perangkat lain. False bila token tidak cocok dengan sesi milik akun ini, atau itu sesi yang sedang dipakai. */
    public function revoke(User $user, string $token, string $currentId): bool
    {
        if (! $this->supported()) {
            return false;
        }

        $session = $this->table()->where('user_id', $user->id)->where('id', '!=', $currentId)->get()
            ->first(fn (object $row) => hash_equals($this->token($row->id), $token));

        if (! $session) {
            return false;
        }

        DB::transaction(function () use ($user, $session) {
            $this->table()->where('id', $session->id)->delete();
            AuditLog::record('profile.session_revoked', $user, ['count' => 1], $user->id);
        });

        return true;
    }

    /** Keluarkan semua perangkat lain; sesi yang sedang dipakai tetap. Mengembalikan jumlahnya. */
    public function revokeOthers(User $user, string $currentId): int
    {
        if (! $this->supported()) {
            return 0;
        }

        return DB::transaction(function () use ($user, $currentId) {
            $count = $this->table()->where('user_id', $user->id)->where('id', '!=', $currentId)->delete();

            if ($count > 0) {
                AuditLog::record('profile.session_revoked', $user, ['count' => $count], $user->id);
            }

            return $count;
        });
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        return DB::table(config('session.table', 'sessions'));
    }

    private function token(string $sessionId): string
    {
        return substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 32);
    }

    /** Nama browser dan sistem operasi secara kasar dari user agent; cukup untuk mengenali perangkat. @return array{browser: string, os: string, mobile: bool} */
    public function describe(string $agent): array
    {
        $browser = match (true) {
            (bool) preg_match('~Edg(e|A|iOS)?/~', $agent) => 'Edge',
            (bool) preg_match('~OPR/|Opera~', $agent) => 'Opera',
            (bool) preg_match('~Firefox/|FxiOS/~', $agent) => 'Firefox',
            (bool) preg_match('~Chrome/|CriOS/~', $agent) => 'Chrome',
            (bool) preg_match('~Safari/~', $agent) => 'Safari',
            default => 'Browser lain',
        };

        $os = match (true) {
            (bool) preg_match('~Windows~', $agent) => 'Windows',
            (bool) preg_match('~Android~', $agent) => 'Android',
            (bool) preg_match('~iPhone|iPad|iPod~', $agent) => 'iOS',
            (bool) preg_match('~Mac OS X|Macintosh~', $agent) => 'macOS',
            (bool) preg_match('~Linux|X11~', $agent) => 'Linux',
            default => 'Perangkat lain',
        };

        return ['browser' => $browser, 'os' => $os, 'mobile' => (bool) preg_match('~Mobile|Android|iPhone|iPad~', $agent)];
    }
}
