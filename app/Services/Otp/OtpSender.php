<?php

namespace App\Services\Otp;

/**
 * Pengirim kode OTP ke nomor WhatsApp (D-093). Melempar exception bila pesan tidak diterima penyedia.
 */
interface OtpSender
{
    public function send(string $phone, string $code): void;
}
