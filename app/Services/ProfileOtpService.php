<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\ProfileChangeCode;
use App\Services\Otp\OtpSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Kode OTP untuk staf yang mengubah data profilnya sendiri, lewat email akun atau WhatsApp (nomor yang sudah terverifikasi).
 * Aturan sama dengan OTP lain di Tuffero: 6 digit, 5 menit, disimpan HMAC, 5 percobaan lalu kunci 15 menit, kirim ulang 60 detik.
 * Kode terikat ke akun dan saluran, jadi tidak bisa dipakai di akun lain. Perubahan oleh Super Admin lewat halaman Pengguna tidak memakai ini.
 */
class ProfileOtpService
{
    public const CHANNELS = ['email', 'phone'];

    private const TTL_SECONDS = 300;

    private const MAX_ATTEMPTS = 5;

    private const LOCK_SECONDS = 900;

    private const RESEND_SECONDS = 60;

    public function __construct(private OtpSender $sender) {}

    /** Saluran yang boleh dipakai akun ini: email selalu, WhatsApp hanya bila nomornya sudah terverifikasi. */
    public function channelsFor(User $user): array
    {
        return $user->phone && $user->phone_verified_at ? self::CHANNELS : ['email'];
    }

    /** @return array{expires_in: int, resend_in: int} */
    public function send(User $user, string $channel): array
    {
        if (! in_array($channel, $this->channelsFor($user), true)) {
            throw $this->rejected('channel', 'Saluran ini belum bisa dipakai. Nomor WhatsApp harus sudah terverifikasi.', 422);
        }

        $id = $this->id($user, $channel);

        if (Cache::has("profile-otp-locked:{$id}")) {
            throw $this->rejected('code', 'Terlalu banyak percobaan salah. Coba lagi 15 menit lagi.', 429);
        }

        if (! Cache::add("profile-otp-resend:{$id}", true, self::RESEND_SECONDS)) {
            throw $this->rejected('code', 'Tunggu sebentar sebelum meminta kode baru.', 429);
        }

        try {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            if ($channel === 'email') {
                $user->notify(new ProfileChangeCode($code, intdiv(self::TTL_SECONDS, 60)));
            } else {
                $this->sender->send((string) $user->phone, $code);
            }
        } catch (\Throwable $e) {
            // Pengiriman yang gagal tidak boleh menahan pengguna 60 detik untuk kode yang tidak pernah sampai.
            Cache::forget("profile-otp-resend:{$id}");
            Log::warning('Pengiriman kode perubahan profil gagal.', ['channel' => $channel, 'error' => $e::class]);

            throw $this->rejected('code', 'Kode belum terkirim. Coba lagi sebentar, atau pakai saluran lain.', 503);
        }

        $now = now()->getTimestamp();
        Cache::put("profile-otp:{$id}", ['hash' => $this->hash($id, $code), 'attempts' => 0, 'expires_at' => $now + self::TTL_SECONDS], self::TTL_SECONDS);

        return ['expires_in' => self::TTL_SECONDS, 'resend_in' => self::RESEND_SECONDS];
    }

    public function verify(User $user, string $channel, string $code): bool
    {
        $id = $this->id($user, $channel);

        return Cache::lock("profile-otp-verify:{$id}", 5)->block(3, function () use ($id, $code) {
            $key = "profile-otp:{$id}";
            $entry = Cache::get($key);

            if (Cache::has("profile-otp-locked:{$id}") || ! is_array($entry)) {
                return false;
            }

            if (hash_equals($entry['hash'], $this->hash($id, $code))) {
                Cache::forget($key);

                return true;
            }

            if (++$entry['attempts'] >= self::MAX_ATTEMPTS) {
                Cache::forget($key);
                Cache::put("profile-otp-locked:{$id}", true, self::LOCK_SECONDS);
            } else {
                Cache::put($key, $entry, max(1, $entry['expires_at'] - now()->getTimestamp()));
            }

            return false;
        });
    }

    private function id(User $user, string $channel): string
    {
        return $user->id.':'.$channel;
    }

    /** HMAC dengan APP_KEY: kode 6 digit dengan SHA-256 biasa mudah ditebak bila isi cache bocor. */
    private function hash(string $id, string $code): string
    {
        return hash_hmac('sha256', 'profile|'.$id.'|'.$code, (string) config('app.key'));
    }

    private function rejected(string $field, string $message, int $status): ValidationException
    {
        return ValidationException::withMessages([$field => $message])->status($status);
    }
}
