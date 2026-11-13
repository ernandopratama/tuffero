<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LoginFailure;
use App\Models\User;
use App\Services\DeviceService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(private DeviceService $devices) {}

    public function login(Request $request): JsonResponse
    {
        // Masuk dengan email atau username. Kolom `email` tetap diterima untuk klien lama (login toko mode dev).
        $request->merge(['login' => $request->input('login', $request->input('email'))]);
        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required'],
        ]);
        $login = trim($credentials['login']);

        // Username tidak boleh memuat "@", jadi tidak ada tabrakan dengan email.
        $user = str_contains($login, '@')
            ? User::where('email', $login)->first()
            : User::where('username', mb_strtolower($login))->first();

        if (! $user || ! Auth::validate(['email' => $user->email, 'password' => $credentials['password'], 'is_active' => true])) {
            LoginFailure::record($login);

            return $this->error('The provided credentials do not match our records.', [], 401);
        }

        $request->session()->regenerate();

        if ($user->mfa_required) {
            $request->session()->put('mfa', [
                'pending_user_id' => $user->id,
                'pending_expires_at' => now()->addMinutes(5)->getTimestamp(),
            ]);

            return $this->success('Password verified.', [
                'mfa' => $user->mfa_totp_confirmed_at ? 'challenge' : 'setup',
            ]);
        }

        Auth::guard('web')->login($user);
        $this->devices->recordLogin($user, $request);

        return $this->success('Login successful.', [
            'user' => $user->accessProfile(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->success('Logout successful.');
    }

    public function user(Request $request): JsonResponse
    {
        return $this->success('User retrieved successfully.', [
            'user' => $request->user()->accessProfile(),
        ]);
    }
}
