<?php

namespace App\Services;

use App\Models\User;
use App\Services\Otp\OtpSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie as HttpCookie;

/**
 * OTP WhatsApp pelanggan (D-051, D-093): 6 digit, 5 menit, sekali pakai, disimpan hash, 5 percobaan lalu kunci 15 menit,
 * kirim ulang 60 detik. Tiap pesan berbiaya, jadi semua pemeriksaan yang bisa menghindari pengiriman dilakukan lebih dulu.
 */
class PhoneOtpService
{
    public const DEVICE_COOKIE = 'tf_phone_verified';

    private const DIGITS = 6;

    private const TTL_SECONDS = 300;

    private const MAX_ATTEMPTS = 5;

    private const LOCK_SECONDS = 900;

    private const RESEND_SECONDS = 60;

    private const MAX_SENDS_PER_PHONE_HOUR = 3;

    private const MAX_SENDS_PER_IP_HOUR = 10;

    public function __construct(private OtpSender $sender, private TurnstileService $turnstile) {}

    /**
     * Dengan $probe, hanya memeriksa status tanpa mengirim (dipakai saat langkah OTP checkout dibuka).
     *
     * @return array{status: 'login_required'|'verified'|'code_active'|'required'|'sent', expires_in?: int, resend_in?: int}
     */
    public function request(string $phone, string $ip, bool $resend, ?string $captchaToken, ?string $deviceCookie, bool $probe = false): array
    {
        if ($this->isMemberPhone($phone)) {
            return ['status' => 'login_required'];
        }

        if ($this->deviceVerified($deviceCookie, $phone)) {
            return ['status' => 'verified'];
        }

        $id = $this->id($phone);

        if (Cache::has("phone-otp-locked:{$id}")) {
            throw $this->rejected('otp_locked', 429);
        }

        $entry = Cache::get("phone-otp:{$id}");

        if (is_array($entry) && ! $resend) {
            return ['status' => 'code_active'] + $this->timers($entry);
        }

        if ($probe) {
            return ['status' => 'required'];
        }

        if (! Cache::add("phone-otp-resend:{$id}", true, self::RESEND_SECONDS)) {
            throw $this->rejected('otp_resend_wait', 429);
        }

        try {
            return $this->deliver($phone, $id, $ip, $captchaToken);
        } catch (\Throwable $e) {
            // Pengiriman yang gagal tidak boleh menahan pelanggan 60 detik untuk kode yang tidak pernah sampai.
            Cache::forget("phone-otp-resend:{$id}");

            if ($e instanceof ValidationException) {
                throw $e;
            }

            Log::warning('Pengiriman OTP WhatsApp gagal.', ['status' => $e->getCode()]);

            throw $this->rejected('otp_unavailable', 503);
        }
    }

    public function verify(string $phone, string $code): bool
    {
        $id = $this->id($phone);

        return Cache::lock("phone-otp-verify:{$id}", 5)->block(3, function () use ($phone, $code, $id) {
            $key = "phone-otp:{$id}";
            $entry = Cache::get($key);

            if (Cache::has("phone-otp-locked:{$id}") || ! is_array($entry)) {
                return false;
            }

            if (hash_equals($entry['hash'], $this->hash($phone, $code))) {
                Cache::forget($key);

                return true;
            }

            if (++$entry['attempts'] >= self::MAX_ATTEMPTS) {
                Cache::forget($key);
                Cache::put("phone-otp-locked:{$id}", true, self::LOCK_SECONDS);
            } else {
                Cache::put($key, $entry, max(1, $entry['expires_at'] - now()->getTimestamp()));
            }

            return false;
        });
    }

    /** Checkout tamu (BR-02): nomor member wajib masuk ke akunnya, nomor lain wajib lolos OTP di perangkat ini. */
    public function assertGuestMayCheckout(?string $deviceCookie, string $phone): void
    {
        $error = match (true) {
            $this->isMemberPhone($phone) => 'phone_requires_login',
            ! $this->deviceVerified($deviceCookie, $phone) => 'phone_unverified',
            default => null,
        };

        if ($error) {
            throw ValidationException::withMessages(['customer_phone' => $error]);
        }
    }

    /** Status verifikasi nomor tamu pada perangkat ini (D-051). Nilai cookie sudah didekripsi middleware. */
    public function deviceVerified(?string $cookie, string $phone): bool
    {
        $data = json_decode((string) $cookie, true);

        return is_array($data) && ($data['phone'] ?? null) === $phone && (int) ($data['expires_at'] ?? 0) > now()->getTimestamp();
    }

    // shortcut: satu perangkat hanya mengingat satu nomor, simpan beberapa nomor bila banyak pelanggan bergantian nomor.
    public function deviceCookie(string $phone): HttpCookie
    {
        $expires = now()->addMonth();

        return Cookie::make(
            self::DEVICE_COOKIE,
            (string) json_encode(['phone' => $phone, 'expires_at' => $expires->getTimestamp()]),
            (int) ceil(now()->diffInMinutes($expires)),
            secure: app()->isProduction() ?: null,
            httpOnly: true,
            sameSite: 'lax',
        );
    }

    /** @return array{status: 'sent', expires_in: int, resend_in: int} */
    private function deliver(string $phone, string $id, string $ip, ?string $captchaToken): array
    {
        $phoneLimit = "phone-otp-send:{$id}";
        $ipLimit = "phone-otp-send-ip:{$ip}";

        if (RateLimiter::tooManyAttempts($phoneLimit, self::MAX_SENDS_PER_PHONE_HOUR) || RateLimiter::tooManyAttempts($ipLimit, self::MAX_SENDS_PER_IP_HOUR)) {
            throw $this->rejected('otp_send_limit', 429);
        }

        if (! $this->turnstile->passes($captchaToken, $ip)) {
            throw $this->rejected('captcha_failed', 422);
        }

        $code = str_pad((string) random_int(0, 10 ** self::DIGITS - 1), self::DIGITS, '0', STR_PAD_LEFT);
        $this->sender->send($phone, $code);

        RateLimiter::hit($phoneLimit, 3600);
        RateLimiter::hit($ipLimit, 3600);

        $entry = ['hash' => $this->hash($phone, $code), 'attempts' => 0, 'sent_at' => now()->getTimestamp(), 'expires_at' => now()->getTimestamp() + self::TTL_SECONDS];
        Cache::put("phone-otp:{$id}", $entry, self::TTL_SECONDS);

        return ['status' => 'sent'] + $this->timers($entry);
    }

    /**
     * @param  array{sent_at: int, expires_at: int}  $entry
     * @return array{expires_in: int, resend_in: int}
     */
    private function timers(array $entry): array
    {
        return [
            'expires_in' => max(0, $entry['expires_at'] - now()->getTimestamp()),
            'resend_in' => max(0, $entry['sent_at'] + self::RESEND_SECONDS - now()->getTimestamp()),
        ];
    }

    private function isMemberPhone(string $phone): bool
    {
        return User::where('phone', $phone)->whereNotNull('phone_verified_at')->exists();
    }

    /** Kunci cache memakai hash supaya nomor pelanggan tidak tersimpan apa adanya di tabel cache. */
    private function id(string $phone): string
    {
        return hash('sha256', $phone);
    }

    /** HMAC dengan APP_KEY: kode 6 digit dengan SHA-256 biasa mudah ditebak bila isi cache bocor. */
    private function hash(string $phone, string $code): string
    {
        return hash_hmac('sha256', $phone.'|'.$code, (string) config('app.key'));
    }

    private function rejected(string $code, int $status): ValidationException
    {
        return ValidationException::withMessages(['phone' => $code])->status($status);
    }
}
