<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required'],
        ]);

        if (! Auth::validate([...$credentials, 'is_active' => true])) {
            return $this->error('The provided credentials do not match our records.', [], 401);
        }

        $user = User::where('email', $credentials['email'])->firstOrFail();

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
