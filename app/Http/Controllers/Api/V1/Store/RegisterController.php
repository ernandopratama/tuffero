<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\RegisterRequest;
use App\Services\RegistrationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Pendaftaran pelanggan dengan kode verifikasi email atau WhatsApp (D-051, D-093, D-095).
 */
class RegisterController extends Controller
{
    use ApiResponse;

    private const SESSION_KEY = 'register.pending';

    public function __construct(private RegistrationService $registration) {}

    public function send(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->registration->assertAvailable($data['email'], $data['phone'] ?? null);

        $pending = [
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'channel' => $data['channel'],
        ];
        $request->session()->put(self::SESSION_KEY, $pending);

        return $this->success('Registration code processed.', $this->registration->send($pending, (string) $request->ip(), false, $data['captcha_token'] ?? null));
    }

    public function resend(RegisterRequest $request): JsonResponse
    {
        $pending = $this->pending($request);

        return $this->success('Registration code processed.', $this->registration->send($pending, (string) $request->ip(), true, $request->validated('captcha_token')));
    }

    public function verify(RegisterRequest $request): JsonResponse
    {
        $pending = $this->pending($request);
        $user = $this->registration->verify($pending, $request->validated('code'));

        if (! $user) {
            throw ValidationException::withMessages(['code' => 'otp_invalid']);
        }

        $request->session()->forget(self::SESSION_KEY);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return $this->success('Registration complete.', ['registered' => true]);
    }

    /** @return array{email: string, phone: ?string, password: string, channel: 'email'|'phone'} */
    private function pending(RegisterRequest $request): array
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending)) {
            throw ValidationException::withMessages(['otp' => 'otp_expired'])->status(422);
        }

        return $pending;
    }
}
