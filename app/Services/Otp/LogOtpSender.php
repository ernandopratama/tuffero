<?php

namespace App\Services\Otp;

use Illuminate\Support\Facades\Log;

/**
 * Hanya untuk local dan testing (lihat AppServiceProvider): kode ditulis ke log supaya development tidak memakai biaya pesan.
 */
class LogOtpSender implements OtpSender
{
    public function send(string $phone, string $code): void
    {
        Log::info('Kode OTP WhatsApp lokal.', ['phone' => $phone, 'code' => $code]);
    }
}
