<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\RegisterEmailCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pendaftaran pelanggan dengan verifikasi kode lewat email atau WhatsApp (D-051, D-093): pelanggan memilih satu saluran.
 * Data pendaftaran ($pending) disimpan di sesi peramban yang mendaftar, bukan di cache per email, supaya orang lain
 * yang mengetahui email itu tidak bisa mengganti kata sandi di tengah proses. Kode email mengikuti aturan kode OTP WhatsApp:
 * 6 digit, 5 menit, disimpan HMAC, 5 percobaan lalu kunci 15 menit, kirim ulang 60 detik.
 */
class RegistrationService
{
    private const TTL_SECONDS = 300;

    private const MAX_ATTEMPTS = 5;

    private const LOCK_SECONDS = 900;

    private const RESEND_SECONDS = 60;

    private const MAX_SENDS_PER_EMAIL_HOUR = 3;

    private const MAX_SENDS_PER_IP_HOUR = 10;

    public function __construct(private PhoneOtpService $phoneOtp) {}

    /** Email dan nomor yang sudah dipakai ditolak sebelum kode apa pun dikirim (kode berbiaya). */
    public function assertAvailable(string $email, ?string $phone): void
    {
        if (User::where('email', $email)->exists()) {
            throw $this->rejected('email', 'email_taken', 422);
        }

        if ($phone && User::where('phone', $phone)->exists()) {
            throw $this->rejected('phone', 'phone_taken', 422);
        }
    }

    /**
     * @param  array{email: string, phone: ?string, password: string, channel: 'email'|'phone'}  $pending  password sudah di-hash
     * @return array{channel: string, status: string, expires_in?: int, resend_in?: int}
     */
    public function send(array $pending, string $ip, bool $resend, ?string $captchaToken): array
    {
        if ($pending['channel'] === 'phone') {
            // Cookie perangkat sengaja null: pendaftaran selalu butuh kode baru, bukan status verifikasi checkout.
            return ['channel' => 'phone'] + $this->phoneOtp->request((string) $pending['phone'], $ip, $resend, $captchaToken, null);
        }

        return ['channel' => 'email'] + $this->sendEmail($pending['email'], $ip, $resend);
    }

    /** Membuat akun bila kode benar. Null berarti kode salah, kedaluwarsa, atau terkunci. */
    public function verify(array $pending, string $code): ?User
    {
        $ok = $pending['channel'] === 'phone'
            ? $this->phoneOtp->verify((string) $pending['phone'], $code)
            : $this->verifyEmail($pending['email'], $code);

        return $ok ? $this->createUser($pending) : null;
    }

    private function createUser(array $pending): User
    {
        $viaPhone = $pending['channel'] === 'phone';
        // Nomor yang belum terverifikasi tidak disimpan: kolom phone unik, jadi nomor yang belum dibuktikan bisa memblokir pemilik aslinya.
        $user = new User(['name' => Str::limit(Str::before($pending['email'], '@'), 100, ''), 'email' => $pending['email'], 'password' => $pending['password']]);
        $user->forceFill([
            'email_verified_at' => $viaPhone ? null : now(),
            'phone' => $viaPhone ? $pending['phone'] : null,
            'phone_verified_at' => $viaPhone ? now() : null,
            'mfa_required' => false,
            'is_active' => true,
        ]);

        try {
            $user->save();
        } catch (UniqueConstraintViolationException) {
            throw $this->rejected('email', 'email_taken', 422);
        }

        return $user;
    }

    /** @return array{status: 'sent'|'code_active', expires_in: int, resend_in: int} */
    private function sendEmail(string $email, string $ip, bool $resend): array
    {
        $id = $this->id($email);

        if (Cache::has("register-email-locked:{$id}")) {
            throw $this->rejected('otp', 'otp_locked', 429);
        }

        $entry = Cache::get("register-email:{$id}");

        if (is_array($entry) && ! $resend) {
            return ['status' => 'code_active'] + $this->timers($entry);
        }

        if (! Cache::add("register-email-resend:{$id}", true, self::RESEND_SECONDS)) {
            throw $this->rejected('otp', 'otp_resend_wait', 429);
        }

        try {
            $emailLimit = "register-email-send:{$id}";
            $ipLimit = "register-email-send-ip:{$ip}";

            if (RateLimiter::tooManyAttempts($emailLimit, self::MAX_SENDS_PER_EMAIL_HOUR) || RateLimiter::tooManyAttempts($ipLimit, self::MAX_SENDS_PER_IP_HOUR)) {
                throw $this->rejected('otp', 'otp_send_limit', 429);
            }

            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            Notification::route('mail', $email)->notify(new RegisterEmailCode($code, intdiv(self::TTL_SECONDS, 60)));

            RateLimiter::hit($emailLimit, 3600);
            RateLimiter::hit($ipLimit, 3600);

            $entry = ['hash' => $this->hash($email, $code), 'attempts' => 0, 'sent_at' => now()->getTimestamp(), 'expires_at' => now()->getTimestamp() + self::TTL_SECONDS];
            Cache::put("register-email:{$id}", $entry, self::TTL_SECONDS);

            return ['status' => 'sent'] + $this->timers($entry);
        } catch (\Throwable $e) {
            // Pengiriman yang gagal tidak boleh menahan pelanggan 60 detik untuk kode yang tidak pernah sampai.
            Cache::forget("register-email-resend:{$id}");

            if ($e instanceof ValidationException) {
                throw $e;
            }

            Log::warning('Pengiriman kode email pendaftaran gagal.', ['error' => $e::class]);

            throw $this->rejected('otp', 'otp_unavailable', 503);
        }
    }

    private function verifyEmail(string $email, string $code): bool
    {
        $id = $this->id($email);

        return Cache::lock("register-email-verify:{$id}", 5)->block(3, function () use ($email, $code, $id) {
            $key = "register-email:{$id}";
            $entry = Cache::get($key);

            if (Cache::has("register-email-locked:{$id}") || ! is_array($entry)) {
                return false;
            }

            if (hash_equals($entry['hash'], $this->hash($email, $code))) {
                Cache::forget($key);

                return true;
            }

            if (++$entry['attempts'] >= self::MAX_ATTEMPTS) {
                Cache::forget($key);
                Cache::put("register-email-locked:{$id}", true, self::LOCK_SECONDS);
            } else {
                Cache::put($key, $entry, max(1, $entry['expires_at'] - now()->getTimestamp()));
            }

            return false;
        });
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

    /** Kunci cache memakai hash supaya alamat email pelanggan tidak tersimpan apa adanya di tabel cache. */
    private function id(string $email): string
    {
        return hash('sha256', $email);
    }

    /** HMAC dengan APP_KEY: kode 6 digit dengan SHA-256 biasa mudah ditebak bila isi cache bocor. */
    private function hash(string $email, string $code): string
    {
        return hash_hmac('sha256', 'register|'.$email.'|'.$code, (string) config('app.key'));
    }

    private function rejected(string $field, string $code, int $status): ValidationException
    {
        return ValidationException::withMessages([$field => $code])->status($status);
    }
}
