<?php

namespace App\Services\Otp;

use RuntimeException;

/**
 * Dipasang di luar local selama Meta Cloud API belum dikonfigurasi, supaya kode tidak pernah tertulis ke log server.
 */
class UnavailableOtpSender implements OtpSender
{
    public function send(string $phone, string $code): void
    {
        throw new RuntimeException('WhatsApp OTP is not configured.');
    }
}
