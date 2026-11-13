<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * Masuk atau daftar pelanggan dengan Google (D-051). Hanya untuk pelanggan: akun staf tidak pernah bisa masuk lewat sini,
 * karena jalur ini tidak melewati langkah MFA mereka (D-030).
 */
class GoogleAuthController extends Controller
{
    public function redirect(): SymfonyRedirect
    {
        if (! self::enabled()) {
            return redirect('/masuk?google=unavailable');
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if (! self::enabled() || $request->has('error')) {
            return redirect('/masuk?google=failed');
        }

        try {
            $google = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            report($e);

            return redirect('/masuk?google=failed');
        }

        // Email wajib terverifikasi oleh Google; tanpa itu kita tidak boleh menautkannya ke akun mana pun.
        if (! $google->getEmail() || ($google->user['email_verified'] ?? false) !== true) {
            return redirect('/masuk?google=failed');
        }

        $user = $this->resolveUser($google);

        if (! $user) {
            return redirect('/masuk?google=failed');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect('/akun');
    }

    public static function enabled(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    private function resolveUser(\Laravel\Socialite\Contracts\User $google): ?User
    {
        $existing = User::where('google_id', $google->getId())->first()
            ?? User::where('email', $google->getEmail())->first();

        if ($existing) {
            // Staf dan akun nonaktif ditolak sebelum ditautkan; akun yang sudah terikat ke Google lain tidak boleh diambil alih lewat email.
            if (! $existing->is_active || $existing->roles()->exists() || ($existing->google_id && $existing->google_id !== $google->getId())) {
                return null;
            }

            if (! $existing->google_id) {
                $existing->forceFill(['google_id' => $google->getId(), 'email_verified_at' => $existing->email_verified_at ?? now()])->save();
            }

            return $existing;
        }

        $user = new User(['name' => Str::limit($google->getName() ?: Str::before($google->getEmail(), '@'), 100, ''), 'email' => $google->getEmail(), 'password' => Str::random(64)]);
        // Pelanggan tidak memakai MFA (khusus staf); kata sandi acak membuat masuk dengan kata sandi tidak mungkin sampai pengguna mengaturnya.
        $user->forceFill(['google_id' => $google->getId(), 'email_verified_at' => now(), 'mfa_required' => false, 'is_active' => true])->save();

        return $user;
    }
}
