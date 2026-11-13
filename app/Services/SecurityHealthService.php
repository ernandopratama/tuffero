<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\LoginDevice;
use App\Models\LoginFailure;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sinyal keamanan untuk Super Admin dari data yang sudah tercatat. Hanya jumlah dan sebaran: tidak ada email, IP, password,
 * atau nama akun yang ikut keluar. Tidak mengubah akun, sesi, atau pengaturan apa pun.
 *
 * PROPOSED: batas di bawah belum diputuskan tim. Angka ini hanya penanda untuk dilihat, bukan kebijakan blokir.
 */
class SecurityHealthService
{
    /** Login gagal per jam: peringatan mulai 10, masalah mulai 50. Batas login 5 per menit per email dan IP sudah menahan serangan; ini hanya melihat polanya. */
    public const FAILED_WARN = 10;

    public const FAILED_BAD = 50;

    /** @return list<array{key: string, label: string, status: string, detail: string}> */
    public function checks(): array
    {
        $now = CarbonImmutable::now();

        return [
            $this->failedLogins($now),
            $this->newDevices($now),
            $this->mfaEvents($now),
            $this->staffWithoutMfa(),
        ];
    }

    /** @return array{key: string, label: string, status: string, detail: string} */
    private function failedLogins(CarbonImmutable $now): array
    {
        $recent = LoginFailure::where('created_at', '>=', $now->subHour());
        $total = (clone $recent)->count();

        if ($total === 0) {
            return $this->check('failed_logins', 'Login gagal (1 jam)', 'ok', 'Tidak ada');
        }

        $ips = (clone $recent)->distinct()->count('ip_address');
        $accounts = (clone $recent)->distinct()->count('email_hash');
        $status = $total >= self::FAILED_BAD ? 'bad' : ($total >= self::FAILED_WARN ? 'warn' : 'ok');

        return $this->check('failed_logins', 'Login gagal (1 jam)', $status, "$total percobaan gagal dari $ips IP ke $accounts akun".($ips >= 5 ? '. Banyak IP berbeda, kemungkinan serangan terdistribusi.' : '.'));
    }

    /** Perangkat baru untuk staf yang sudah punya perangkat lain; perangkat pertama sebuah akun bukan peringatan. */
    private function newDevices(CarbonImmutable $now): array
    {
        $count = LoginDevice::where('first_seen_at', '>=', $now->subDay())
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('login_devices as earlier')
                ->whereColumn('earlier.user_id', 'login_devices.user_id')
                ->whereColumn('earlier.first_seen_at', '<', 'login_devices.first_seen_at'))
            ->count();

        return $count === 0
            ? $this->check('new_devices', 'Perangkat baru staf (24 jam)', 'ok', 'Tidak ada')
            : $this->check('new_devices', 'Perangkat baru staf (24 jam)', 'warn', "$count perangkat baru masuk ke akun staf. Staf yang bersangkutan sudah diberi tahu lewat notifikasi.");
    }

    /** Kode email tidak dihitung: itu faktor kedua biasa. Kode pemulihan dan reset MFA jarang dan patut dilihat. */
    private function mfaEvents(CarbonImmutable $now): array
    {
        $recovery = AuditLog::where('action', 'mfa.recovery_code_used')->where('created_at', '>=', $now->subDays(7))->count();
        $reset = AuditLog::where('action', 'mfa.reset')->where('created_at', '>=', $now->subDays(7))->count();

        return $recovery + $reset === 0
            ? $this->check('mfa_events', 'Kode pemulihan dan reset MFA (7 hari)', 'ok', 'Tidak ada')
            : $this->check('mfa_events', 'Kode pemulihan dan reset MFA (7 hari)', 'warn', "$recovery kode pemulihan dipakai, $reset MFA direset. Pastikan itu memang atas permintaan stafnya (audit log).");
    }

    /** @return array{key: string, label: string, status: string, detail: string} */
    private function staffWithoutMfa(): array
    {
        $count = User::where('is_active', true)->whereHas('roles')->whereNull('mfa_totp_confirmed_at')->count();

        return $count === 0
            ? $this->check('staff_no_mfa', 'Staf tanpa MFA', 'ok', 'Semua akun staf aktif memakai MFA')
            : $this->check('staff_no_mfa', 'Staf tanpa MFA', 'warn', "$count akun staf aktif belum memakai MFA.");
    }

    /** @return array{key: string, label: string, status: string, detail: string} */
    private function check(string $key, string $label, string $status, string $detail): array
    {
        return compact('key', 'label', 'status', 'detail');
    }
}
