<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;

class MfaService
{
    private const STEP_SECONDS = 30;

    private const DIGITS = 6;

    private const WINDOW_STEPS = 1;

    private const RECOVERY_CODE_COUNT = 8;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Membuat secret baru yang belum aktif sampai kode pertama terverifikasi.
     *
     * @return array{secret: string, otpauth_uri: string, qr_code: string}
     */
    public function startEnrollment(User $user): array
    {
        $secret = $this->newSecret();

        $user->forceFill(['mfa_totp_secret' => $secret, 'mfa_totp_last_timestep' => null])->save();

        return $this->provisioning($secret, $user->email);
    }

    public function newSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /**
     * Data untuk dipindai atau diketik ke aplikasi autentikator.
     *
     * @return array{secret: string, otpauth_uri: string, qr_code: string}
     */
    public function provisioning(string $secret, string $email): array
    {
        $uri = 'otpauth://totp/'.rawurlencode('Tuffero:'.$email).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => 'Tuffero',
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::STEP_SECONDS,
        ]);

        // QR dibuat di server sendiri: secret tidak boleh dikirim ke layanan QR pihak ketiga.
        $svg = (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd)))->writeString($uri);

        return [
            'secret' => $secret,
            'otpauth_uri' => $uri,
            'qr_code' => 'data:image/svg+xml;base64,'.base64_encode($svg),
        ];
    }

    /**
     * Mengaktifkan TOTP bila kode benar, lalu mengembalikan kode pemulihan. Teks asli hanya ada di sini.
     *
     * @return list<string>|null
     */
    public function confirmEnrollment(User $user, string $code): ?array
    {
        if (! $this->verifyCode($user, $code)) {
            return null;
        }

        $codes = $this->newRecoveryCodes();

        $user->forceFill([
            'mfa_totp_confirmed_at' => now(),
            'mfa_recovery_codes' => array_map($this->hashRecoveryCode(...), $codes),
        ])->save();

        AuditLog::record('mfa.enrolled', $user, actorId: $user->id);

        return $codes;
    }

    /**
     * Mengganti authenticator milik sendiri: kode dari secret BARU harus benar dulu, baru secret lama diganti dan kode pemulihan
     * dibuat ulang (yang lama tidak berlaku lagi). Dikembalikan null bila kode salah; authenticator lama tetap utuh.
     *
     * @return list<string>|null
     */
    public function replaceSecret(User $user, string $newSecret, string $code): ?array
    {
        return DB::transaction(function () use ($user, $newSecret, $code) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $current = (int) floor(now()->getTimestamp() / self::STEP_SECONDS);
            $matched = null;

            for ($step = $current - self::WINDOW_STEPS; $step <= $current + self::WINDOW_STEPS; $step++) {
                if (hash_equals($this->totp($newSecret, $step), $code)) {
                    $matched = $step;
                    break;
                }
            }

            if ($matched === null) {
                return null;
            }

            $codes = $this->newRecoveryCodes();
            $locked->forceFill([
                'mfa_totp_secret' => $newSecret,
                'mfa_totp_last_timestep' => $matched,
                'mfa_totp_confirmed_at' => now(),
                'mfa_recovery_codes' => array_map($this->hashRecoveryCode(...), $codes),
            ])->save();

            AuditLog::record('mfa.replaced_self', $locked, actorId: $locked->id);

            return $codes;
        });
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = implode('-', str_split(bin2hex(random_bytes(5)), 5));
        }

        return $codes;
    }

    public function verifyCode(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (! is_string($locked->mfa_totp_secret)) {
                return false;
            }

            $current = (int) floor(now()->getTimestamp() / self::STEP_SECONDS);

            for ($step = $current - self::WINDOW_STEPS; $step <= $current + self::WINDOW_STEPS; $step++) {
                if ($step > (int) $locked->mfa_totp_last_timestep && hash_equals($this->totp($locked->mfa_totp_secret, $step), $code)) {
                    $locked->forceFill(['mfa_totp_last_timestep' => $step])->save();

                    return true;
                }
            }

            return false;
        });
    }

    public function verifyRecoveryCode(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $hash = $this->hashRecoveryCode($code);
            $remaining = array_values(array_filter(
                $locked->mfa_recovery_codes ?? [],
                fn (string $stored) => ! hash_equals($stored, $hash),
            ));

            if (count($remaining) === count($locked->mfa_recovery_codes ?? [])) {
                return false;
            }

            $locked->forceFill(['mfa_recovery_codes' => $remaining])->save();
            AuditLog::record('mfa.recovery_code_used', $locked, actorId: $locked->id);

            return true;
        });
    }

    /**
     * Menghapus MFA pengguna. Login berikutnya wajib mendaftar ulang.
     */
    public function reset(User $user, ?int $actorId): void
    {
        $user->forceFill([
            'mfa_totp_secret' => null,
            'mfa_totp_confirmed_at' => null,
            'mfa_totp_last_timestep' => null,
            'mfa_recovery_codes' => null,
        ])->save();

        AuditLog::record('mfa.reset', $user, actorId: $actorId);
    }

    /**
     * RFC 6238 dengan HMAC-SHA1, format yang didukung semua aplikasi authenticator.
     */
    public function totp(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $this->base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function hashRecoveryCode(string $code): string
    {
        return hash('sha256', strtolower(trim($code)));
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private function base32Decode(string $encoded): string
    {
        $bits = '';
        foreach (str_split($encoded) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::BASE32_ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int) bindec($chunk));
            }
        }

        return $bytes;
    }
}
