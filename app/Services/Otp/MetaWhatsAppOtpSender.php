<?php

namespace App\Services\Otp;

use Illuminate\Support\Facades\Http;

/**
 * Mengirim template authentication lewat Meta WhatsApp Cloud API (D-093). Tombol salin kode memakai parameter kode yang sama.
 */
class MetaWhatsAppOtpSender implements OtpSender
{
    public function send(string $phone, string $code): void
    {
        $config = config('services.whatsapp');
        $parameters = [['type' => 'text', 'text' => $code]];

        Http::withToken($config['token'])
            ->timeout(10)
            ->post("https://graph.facebook.com/{$config['graph_version']}/{$config['phone_number_id']}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => ltrim($phone, '+'),
                'type' => 'template',
                'template' => [
                    'name' => $config['otp_template'],
                    'language' => ['code' => $config['otp_language']],
                    'components' => [
                        ['type' => 'body', 'parameters' => $parameters],
                        ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => $parameters],
                    ],
                ],
            ])
            ->throw();
    }
}
