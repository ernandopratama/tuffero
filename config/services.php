<?php

use App\Services\MidtransService;

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Midtrans and Biteship keys live only in the server .env (BR-20); never expose them to the frontend.
    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'is_production' => (bool) env('MIDTRANS_IS_PRODUCTION', false),
        // Metode di jendela bayar (keputusan pemilik 2026-11-07): QRIS, virtual account, dan kartu. Ganti lewat MIDTRANS_ENABLED_PAYMENTS
        // (kode Snap dipisah koma); isi `all` untuk tanpa batas (semua metode aktif di dashboard Midtrans).
        'enabled_payments' => (function (): array {
            $value = trim((string) env('MIDTRANS_ENABLED_PAYMENTS', ''));

            if ($value === 'all') {
                return [];
            }

            return $value === ''
                ? MidtransService::DEFAULT_PAYMENT_METHODS
                : array_values(array_filter(array_map('trim', explode(',', $value))));
        })(),
    ],

    // Claude API (D-061): kunci hanya di .env server, tidak pernah ke frontend atau log. Model dipilih per tugas;
    // bawaannya model terkecil dan termurah (Haiku 5.5). Naikkan ke claude-sonnet-5-5 lewat .env bila mutu kurang.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => env('ANTHROPIC_VERSION', '2026-10-21'),
        'workspace_id' => env('ANTHROPIC_WORKSPACE_ID'),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 30),
        // Matikan jawaban AI di chat tanpa deploy: CHAT_AI_ENABLED=false. Tanpa kunci API, chat juga tidak memakai AI.
        'chat_enabled' => (bool) env('CHAT_AI_ENABLED', true),
        'models' => [
            'translation' => env('ANTHROPIC_MODEL_TRANSLATION', 'claude-haiku-5-5'),
            'product_intake' => env('ANTHROPIC_MODEL_PRODUCT_INTAKE', 'claude-haiku-5-5'),
            // Chat toko (D-081). Haiku 5.5 cukup untuk menjawab dari katalog dan FAQ.
            'chat' => env('ANTHROPIC_MODEL_CHAT', 'claude-haiku-5-5'),
        ],
    ],

    'biteship' => [
        'key' => env('BITESHIP_API_KEY'),
        'base_url' => env('BITESHIP_BASE_URL', 'https://api.biteship.com'),
        // Kode pos gudang asal (Malang). Satu gudang aktif; ganti ke data warehouses saat gudang kedua ada.
        'origin_postal_code' => env('BITESHIP_ORIGIN_POSTAL_CODE'),
        // Batas cek tarif per hari untuk seluruh toko; tiap cek memotong 5 pts saldo (D-076). 0 = tanpa batas.
        'daily_rate_limit' => (int) env('BITESHIP_DAILY_RATE_LIMIT', 200),
        // Batas panggilan pelacakan paket per hari untuk seluruh toko (hasil sukses di-cache 15 menit). 0 = tanpa batas.
        'daily_tracking_limit' => (int) env('BITESHIP_DAILY_TRACKING_LIMIT', 500),
        // Rahasia bersama untuk webhook Biteship (dashboard Biteship, header X-Biteship-Secret). Kosong = webhook ditolak semua.
        'webhook_secret' => env('BITESHIP_WEBHOOK_SECRET'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
