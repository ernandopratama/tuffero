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
 * Pendaftaran pelanggan: kode verifikasi email dan WhatsApp, keduanya wajib sebelum akun dibuat (D-051, D-093, D-145).
 * Saklar registration.whatsapp_required (D-146): mati (bawaan) berarti cukup email dan nomor tidak diminta.
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
            'verified' => [],
        ];
        $request->session()->put(self::SESSION_KEY, $pending);

        return $this->success('Registration code processed.', $this->registration->send($pending, (string) $request->ip(), false, $data['captcha_token'] ?? null));
    }

    public function resend(RegisterRequest $request): JsonResponse
    {
        $pending = $this->pending($request);

        return $this->success('Registration code processed.', $this->registration->send($pending, (string) $request->ip(), true, $request->validated('captcha_token')));
    }

    /** Saluran kedua setelah yang pertama terverifikasi; saluran yang sudah selesai tidak dikirimi kode lagi. */
    public function channel(RegisterRequest $request): JsonResponse
    {
        $pending = $this->pending($request);
        $channel = $request->validated('channel');

        if (in_array($channel, $pending['verified'], true)) {
            throw ValidationException::withMessages(['channel' => 'channel_done'])->status(422);
        }

        $pending['channel'] = $channel;
        $request->session()->put(self::SESSION_KEY, $pending);

        return $this->success('Registration code processed.', $this->registration->send($pending, (string) $request->ip(), false, $request->validated('captcha_token')));
    }

    public function verify(RegisterRequest $request): JsonResponse
    {
        $pending = $this->pending($request);

        if (! $this->registration->verify($pending, $request->validated('code'))) {
            throw ValidationException::withMessages(['code' => 'otp_invalid']);
        }

        $pending['verified'] = array_values(array_unique([...$pending['verified'], $pending['channel']]));

        // Saluran yang diwajibkan belum semua selesai: akun belum dibuat (dua saluran bila saklar WhatsApp menyala, D-146).
        if (count($pending['verified']) < (RegistrationService::whatsappRequired() ? 2 : 1)) {
            $request->session()->put(self::SESSION_KEY, $pending);

            return $this->success('Channel verified.', ['registered' => false, 'verified' => $pending['verified']]);
        }

        $user = $this->registration->createUser($pending);
        $request->session()->forget(self::SESSION_KEY);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return $this->success('Registration complete.', ['registered' => true, 'verified' => $pending['verified']]);
    }

    /** @return array{email: string, phone: ?string, password: string, channel: 'email'|'phone', verified: list<string>} */
    private function pending(RegisterRequest $request): array
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending)) {
            throw ValidationException::withMessages(['otp' => 'otp_expired'])->status(422);
        }

        // Sesi yang dimulai sebelum D-145 belum punya daftar saluran terverifikasi.
        $pending['verified'] ??= [];

        return $pending;
    }
}
