<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\PhoneOtpRequest;
use App\Services\PhoneOtpService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Verifikasi nomor WhatsApp tamu (D-051, D-093).
 */
class PhoneOtpController extends Controller
{
    use ApiResponse;

    public function __construct(private PhoneOtpService $otp) {}

    public function send(PhoneOtpRequest $request): JsonResponse
    {
        return $this->success('Phone OTP processed.', $this->otp->request(
            $request->validated('phone'),
            (string) $request->ip(),
            $request->boolean('resend'),
            $request->validated('captcha_token'),
            $request->cookie(PhoneOtpService::DEVICE_COOKIE),
            $request->boolean('probe'),
        ));
    }

    public function verify(PhoneOtpRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');

        if (! $this->otp->verify($phone, (string) $request->validated('code'))) {
            throw ValidationException::withMessages(['code' => 'otp_invalid']);
        }

        return $this->success('Phone verified.', ['verified' => true])->withCookie($this->otp->deviceCookie($phone));
    }
}
