<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MfaService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Langkah kedua login. Sebelum selesai, sesi hanya menyimpan penanda password benar, jadi auth:sanctum tetap menolak.
 */
class MfaController extends Controller
{
    use ApiResponse;

    public function __construct(private MfaService $mfa) {}

    public function setup(Request $request): JsonResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return $this->sessionExpired();
        }

        // Tanpa pemeriksaan ini, pemegang password saja bisa mengganti authenticator yang sudah aktif.
        if ($user->mfa_totp_confirmed_at) {
            return $this->error('MFA is already enrolled.', [], 409);
        }

        return $this->success('MFA enrollment started.', $this->mfa->startEnrollment($user));
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'size:6']]);
        $user = $this->pendingUser($request);

        if (! $user) {
            return $this->sessionExpired();
        }

        if ($user->mfa_totp_confirmed_at) {
            return $this->error('MFA is already enrolled.', [], 409);
        }

        $recoveryCodes = $this->mfa->confirmEnrollment($user, $validated['code']);

        if ($recoveryCodes === null) {
            return $this->error('The provided code is invalid.', [], 422);
        }

        return $this->success('MFA enabled.', [
            'user' => $this->completeLogin($request, $user),
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'size:6'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ]);
        $user = $this->pendingUser($request);

        if (! $user) {
            return $this->sessionExpired();
        }

        if (! $user->mfa_totp_confirmed_at) {
            return $this->error('MFA is not enrolled.', [], 409);
        }

        $verified = isset($validated['code'])
            ? $this->mfa->verifyCode($user, $validated['code'])
            : $this->mfa->verifyRecoveryCode($user, (string) ($validated['recovery_code'] ?? ''));

        if (! $verified) {
            return $this->error('The provided code is invalid.', [], 422);
        }

        return $this->success('Login successful.', ['user' => $this->completeLogin($request, $user)]);
    }

    private function pendingUser(Request $request): ?User
    {
        if (now()->getTimestamp() > (int) $request->session()->get('mfa.pending_expires_at')) {
            $request->session()->forget('mfa');

            return null;
        }

        $user = User::find($request->session()->get('mfa.pending_user_id'));

        return $user?->is_active ? $user : null;
    }

    /** @return array<string, mixed> */
    private function completeLogin(Request $request, User $user): array
    {
        $request->session()->forget('mfa');
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return $user->accessProfile();
    }

    private function sessionExpired(): JsonResponse
    {
        return $this->error('Please log in again.', [], 401);
    }
}
