<?php

namespace App\Services;

use App\Models\PaymentEvent;
use App\Models\ShipmentEvent;
use App\Models\Warehouse;
use App\Models\WebhookRejection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Uji kunci API Midtrans dan Biteship langsung ke penyedia. Hanya dijalankan atas permintaan super-admin
 * (tombol "Cek ke API"), hasilnya disimpan di cache supaya dashboard tidak memanggil jaringan luar.
 * Pesan hasil berupa teks tetap: isi kunci dan isi respons penyedia tidak pernah ikut keluar.
 */
class IntegrationHealthService
{
    public const CACHE_KEY = 'integrations:live';

    private const CACHE_HOURS = 24;

    public function __construct(private MidtransService $midtrans) {}

    /** @return array<string, mixed>|null checked_at dan satu hasil {status, message} per layanan. */
    public function cached(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    public function refresh(): array
    {
        $result = [
            'checked_at' => now()->toIso8601String(),
            'midtrans' => $this->midtrans(),
            'biteship' => $this->biteship(),
            'mail' => $this->mail(),
            'whatsapp' => $this->whatsapp(),
            'turnstile' => $this->turnstile(),
            'claude' => $this->claude(),
        ];

        Cache::put(self::CACHE_KEY, $result, now()->addHours(self::CACHE_HOURS));
        Cache::forget(DashboardService::HEALTH_CACHE_KEY);

        return $result;
    }

    /** Berkas alamat tunnel yang ditulis `php artisan dev:tunnel`. Hanya dibaca di environment local. */
    public static function tunnelFile(): string
    {
        return storage_path('app/dev-tunnel-url');
    }

    /**
     * Tunnel Cloudflare yang sedang berjalan di komputer ini. Hanya di environment local; di tempat lain selalu null.
     * Pertama ditanyakan ke cloudflared sendiri (endpoint metrics di 127.0.0.1, port 20241 sampai 20245), jadi tunnel yang dijalankan
     * dengan cara apa pun terdeteksi. Cadangannya berkas dari `php artisan dev:tunnel`, untuk cloudflared lama tanpa endpoint itu.
     */
    public function activeTunnel(): ?string
    {
        if (! app()->environment('local')) {
            return null;
        }

        foreach (range(20241, 20245) as $port) {
            try {
                $host = Http::connectTimeout(0.3)->timeout(1)->get("http://127.0.0.1:$port/quicktunnel")->json('hostname');
            } catch (Throwable) {
                continue;
            }

            if (is_string($host) && preg_match('~^[a-z0-9-]+\.trycloudflare\.com$~', $host)) {
                return "https://$host";
            }
        }

        $file = self::tunnelFile();
        $saved = is_file($file) && filemtime($file) > time() - 12 * 3600 ? trim((string) file_get_contents($file)) : '';

        return preg_match('~^https://[a-z0-9-]+\.trycloudflare\.com$~', $saved) ? $saved : null;
    }

    /**
     * Alamat dasar webhook yang ditempel ke dashboard Midtrans dan Biteship, sesuai environment:
     * local memakai alamat tunnel aktif bila ada; dev dan production memakai APP_URL (https://tuffero.ernando.my.id, https://tuffero.com).
     * APP_URL yang masih menunjuk localhost di luar local diganti alamat yang sedang dipakai membuka admin.
     *
     * @return array{url: string, source: string, reachable: bool}
     */
    public function webhookBase(): array
    {
        $local = app()->environment('local');

        if ($tunnel = $this->activeTunnel()) {
            return ['url' => $tunnel, 'source' => 'tunnel', 'reachable' => true];
        }

        $url = rtrim((string) config('app.url'), '/');
        $isLocalHost = fn (string $address) => in_array(parse_url($address, PHP_URL_HOST), ['localhost', '127.0.0.1', '::1', '[::1]'], true);

        if (! $local && $isLocalHost($url)) {
            $url = request()->getSchemeAndHttpHost();
        }

        return ['url' => $url, 'source' => 'app_url', 'reachable' => ! $isLocalHost($url)];
    }

    /**
     * Status tiap integrasi dari konfigurasi, riwayat webhook, dan hasil cek API terakhir (cache). Tidak ada panggilan jaringan,
     * dan isi kunci atau rahasia tidak pernah keluar dari sini. Tanda tangan webhook Midtrans dan rahasia webhook Biteship
     * diperiksa di kode saat webhook masuk; di sini hanya dipastikan prasyaratnya terpasang dan webhook pernah sampai.
     * Awalan kunci Midtrans sengaja tidak dipakai menebak mode: format kunci sandbox berubah antar akun, jadi mode yang salah
     * dibuktikan oleh cek API (401), bukan oleh tebakan.
     *
     * @return list<array{key: string, title: string, status: string, issues: list<string>, last: ?CarbonImmutable, last_label: string, checked: ?CarbonImmutable, live: ?array{status: string, message: string}, rejected: int}>
     */
    public function overview(): array
    {
        $production = app()->isProduction();
        $unsafeUrl = $production && ! str_starts_with((string) config('app.url'), 'https://') ? 'APP_URL bukan HTTPS, alamat webhook tidak aman' : null;

        $midtrans = [];
        $midtransWarn = [];

        if (! $this->midtrans->enabled()) {
            $midtrans[] = 'Kunci server atau klien belum diisi';
        } elseif ((bool) config('services.midtrans.is_production') && str_starts_with((string) config('services.midtrans.server_key'), 'SB-')) {
            $midtrans[] = 'Mode produksi tetapi kunci sandbox';
        } elseif (! config('services.midtrans.is_production') && $production) {
            $midtransWarn[] = 'Masih mode sandbox di lingkungan produksi';
        }

        $secret = (string) config('services.biteship.webhook_secret');
        $biteship = [];
        $biteshipWarn = [];

        if (! filled(config('services.biteship.key'))) {
            $biteship[] = 'Kunci API belum diisi';
        }
        if (! filled(Warehouse::originPostalCode())) {
            $biteship[] = 'Kode pos asal belum diisi (di halaman Gudang), tarif tidak bisa dihitung';
        }
        if ($secret === '') {
            $biteship[] = 'Rahasia webhook kosong, semua webhook ditolak';
        } elseif (mb_strlen($secret) < 16) {
            $biteshipWarn[] = 'Rahasia webhook terlalu pendek (minimal 16 karakter)';
        }

        if ($unsafeUrl) {
            $midtrans[] = $unsafeUrl;
            $biteship[] = $unsafeUrl;
        }

        $live = $this->cached();
        $rejected = WebhookRejection::where('created_at', '>=', now()->subDay())->selectRaw('provider, COUNT(*) as total')->groupBy('provider')->pluck('total', 'provider');
        $checkedAt = isset($live['checked_at']) ? CarbonImmutable::parse($live['checked_at']) : null;
        $at = fn (?string $value) => $value ? CarbonImmutable::parse($value) : null;

        $build = function (string $key, string $title, array $bad, array $warn, ?CarbonImmutable $last, string $lastLabel) use ($live, $rejected, $checkedAt): array {
            $check = $live[$key] ?? null;
            $count = (int) ($rejected[$key] ?? 0);

            if (($check['status'] ?? null) === 'bad') {
                $bad[] = $check['message'];
            } elseif (($check['status'] ?? null) === 'unknown') {
                $warn[] = $check['message'];
            }
            if ($count > 0) {
                $warn[] = "$count webhook ditolak dalam 24 jam (rahasia atau tanda tangan salah)";
            }

            return [
                'key' => $key, 'title' => $title, 'status' => $bad ? 'bad' : ($warn ? 'warn' : 'ok'), 'issues' => [...$bad, ...$warn],
                'last' => $last, 'last_label' => $lastLabel, 'checked' => $checkedAt, 'live' => $check, 'rejected' => $count,
            ];
        };

        return [
            $build('midtrans', 'Midtrans', $midtrans, $midtransWarn, $at(PaymentEvent::max('created_at')), 'Notifikasi terakhir'),
            $build('biteship', 'Biteship', $biteship, $biteshipWarn, $at(ShipmentEvent::max('created_at')), 'Pembaruan kurir terakhir'),
        ];
    }

    /**
     * Layanan pendukung selain pembayaran dan pengiriman: email, OTP WhatsApp, Turnstile, dan Claude API. Dari konfigurasi dan hasil
     * cek API terakhir (cache), tanpa panggilan jaringan. Layanan yang memang tidak dipakai (misalnya OTP WhatsApp bila belum diwajibkan)
     * tidak dihitung masalah. Di lokal, yang sengaja dilewati (email ke log, Turnstile) juga bukan masalah.
     *
     * @return list<array{key: string, title: string, status: string, issues: list<string>, note: ?string, live: ?array{status: string, message: string}}>
     */
    public function services(): array
    {
        $local = app()->environment('local');

        // Email: di luar lokal, mailer log atau array berarti OTP dan notifikasi email tidak benar-benar terkirim.
        $mailer = (string) config('mail.default');
        $bad = [];
        $warn = [];
        $note = null;

        if (in_array($mailer, ['log', 'array'], true)) {
            $local ? $note = "Lokal: email hanya ditulis ke log (MAIL_MAILER=$mailer)" : $bad[] = "MAIL_MAILER=$mailer: OTP dan notifikasi email tidak benar-benar terkirim";
        } elseif ($mailer === 'smtp') {
            if (! $local && in_array((string) config('mail.mailers.smtp.host'), ['127.0.0.1', 'localhost', ''], true)) {
                $bad[] = 'MAIL_HOST masih menunjuk komputer ini, bukan server SMTP';
            }
        } elseif (! filled(config("services.$mailer.key")) && in_array($mailer, ['resend', 'postmark'], true)) {
            $bad[] = "Kunci API $mailer belum diisi";
        }
        if (! $local && (str_ends_with((string) config('mail.from.address'), '@example.com') || ! filled(config('mail.from.address')))) {
            $warn[] = 'MAIL_FROM_ADDRESS masih alamat contoh';
        }
        $mail = $this->service('mail', 'Email', $bad, $warn, $note);

        // OTP WhatsApp: hanya masalah bila diwajibkan tetapi belum lengkap.
        $configured = filled(config('services.whatsapp.token')) && filled(config('services.whatsapp.phone_number_id')) && filled(config('services.whatsapp.otp_template'));
        $required = (bool) config('services.whatsapp.otp_required');
        $bad = [];
        $warn = [];
        $note = null;

        if (! $configured) {
            if ($required) {
                $bad[] = 'OTP WhatsApp diwajibkan tetapi token, phone number ID, atau template belum diisi: checkout tamu gagal';
            } elseif ($local) {
                $note = 'Lokal: OTP ditulis ke log';
            } else {
                $warn[] = 'Belum dikonfigurasi: pengiriman OTP WhatsApp di luar lokal ditolak';
            }
        } elseif (! $required) {
            $note = 'Terkonfigurasi; OTP checkout belum diwajibkan (WHATSAPP_OTP_REQUIRED=false)';
        }
        $whatsapp = $this->service('whatsapp', 'OTP WhatsApp', $bad, $warn, $note);

        // Turnstile: tanpa rahasia, permintaan yang memakainya ditolak di luar lokal.
        $bad = [];
        $warn = [];
        $note = null;

        if (! filled(config('services.turnstile.secret'))) {
            $local ? $note = 'Lokal: pemeriksaan Turnstile dilewati' : $bad[] = 'Rahasia Turnstile kosong: permintaan yang memakai Turnstile ditolak';
        }
        if (! filled(config('services.turnstile.site_key')) && ! $local) {
            $warn[] = 'Site key kosong: widget tidak tampil di browser';
        }
        $turnstile = $this->service('turnstile', 'Turnstile', $bad, $warn, $note);

        // Claude API: fitur opsional, tanpa kunci chat AI dan terjemahan produk nonaktif.
        $bad = [];
        $warn = [];
        $note = null;

        if (! filled(config('services.anthropic.key'))) {
            $warn[] = 'Kunci belum diisi: chat AI dan terjemahan produk nonaktif';
        } elseif (! config('services.anthropic.chat_enabled')) {
            $note = 'Chat AI dimatikan (CHAT_AI_ENABLED=false)';
        }
        $claude = $this->service('claude', 'Claude API', $bad, $warn, $note);

        return [$mail, $whatsapp, $turnstile, $claude];
    }

    /**
     * @param  list<string>  $bad
     * @param  list<string>  $warn
     * @return array{key: string, title: string, status: string, issues: list<string>, note: ?string, live: ?array{status: string, message: string}}
     */
    private function service(string $key, string $title, array $bad, array $warn, ?string $note): array
    {
        $live = $this->cached()[$key] ?? null;

        if (($live['status'] ?? null) === 'bad') {
            $bad[] = $live['message'];
        } elseif (($live['status'] ?? null) === 'unknown') {
            $warn[] = $live['message'];
        }

        return ['key' => $key, 'title' => $title, 'status' => $bad ? 'bad' : ($warn ? 'warn' : 'ok'), 'issues' => [...$bad, ...$warn], 'note' => $note, 'live' => $live];
    }

    /** SMTP: hanya memastikan server terjangkau, belum menguji login. Mailer lain tidak punya cek jaringan di sini. @return array{status: string, message: string} */
    private function mail(): array
    {
        if (config('mail.default') !== 'smtp' || ! filled(config('mail.mailers.smtp.host'))) {
            return $this->result('skipped', 'Tidak dicek');
        }

        $socket = @stream_socket_client('tcp://'.config('mail.mailers.smtp.host').':'.(int) config('mail.mailers.smtp.port'), $errno, $error, 5);

        if (! $socket) {
            return $this->result('bad', 'Server SMTP tidak terjangkau dari server ini');
        }

        fclose($socket);

        return $this->result('ok', 'Server SMTP terjangkau (login belum diuji)');
    }

    /**
     * Token WhatsApp dibaca ke Meta lewat pembacaan nomor pengirim; tidak ada pesan yang dikirim.
     *
     * @return array{status: string, message: string}
     */
    private function whatsapp(): array
    {
        $token = config('services.whatsapp.token');
        $phoneId = config('services.whatsapp.phone_number_id');

        if (! filled($token) || ! filled($phoneId)) {
            return $this->result('skipped', 'Tidak dicek');
        }

        try {
            $response = Http::withToken((string) $token)->acceptJson()->timeout(8)->connectTimeout(5)
                ->get('https://graph.facebook.com/'.config('services.whatsapp.graph_version').'/'.rawurlencode((string) $phoneId), ['fields' => 'id']);
        } catch (Throwable $e) {
            Log::warning('WhatsApp key check failed', ['error' => $e->getMessage()]);

            return $this->result('unknown', 'Meta tidak bisa dihubungi');
        }

        return match (true) {
            $response->successful() => $this->result('ok', 'Token diterima Meta'),
            (int) $response->json('error.code') === 190 || $response->status() === 401 => $this->result('bad', 'Token WhatsApp ditolak Meta (salah atau kedaluwarsa)'),
            (int) $response->json('error.code') === 100 || $response->status() === 404 => $this->result('bad', 'Phone number ID tidak ditemukan atau tidak boleh diakses token ini'),
            default => $this->result('unknown', 'Respons Meta tak terduga ('.$response->status().')'),
        };
    }

    /**
     * Turnstile menolak token palsu dengan invalid-input-response bila rahasia benar, dan invalid-input-secret bila rahasia salah.
     *
     * @return array{status: string, message: string}
     */
    private function turnstile(): array
    {
        $secret = config('services.turnstile.secret');

        if (! filled($secret)) {
            return $this->result('skipped', 'Tidak dicek');
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(8)->connectTimeout(5)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['secret' => $secret, 'response' => 'tuffero-cek-kunci']);
        } catch (Throwable $e) {
            Log::warning('Turnstile key check failed', ['error' => $e->getMessage()]);

            return $this->result('unknown', 'Cloudflare tidak bisa dihubungi');
        }

        $errors = (array) $response->json('error-codes', []);

        return match (true) {
            in_array('invalid-input-secret', $errors, true) => $this->result('bad', 'Rahasia Turnstile ditolak Cloudflare'),
            in_array('invalid-input-response', $errors, true) => $this->result('ok', 'Rahasia diterima Cloudflare'),
            default => $this->result('unknown', 'Respons Cloudflare tak terduga ('.$response->status().')'),
        };
    }

    /**
     * Sama dengan `php artisan ai:check` untuk model chat: membaca data model, tidak membuat teks, jadi tidak memakai token.
     *
     * @return array{status: string, message: string}
     */
    private function claude(): array
    {
        $key = config('services.anthropic.key');

        if (! filled($key)) {
            return $this->result('skipped', 'Tidak dicek');
        }

        $request = Http::baseUrl((string) config('services.anthropic.base_url'))->acceptJson()->timeout(8)->connectTimeout(5)
            ->withHeaders(['x-api-key' => (string) $key, 'anthropic-version' => (string) config('services.anthropic.version')]);

        if ($workspace = config('services.anthropic.workspace_id')) {
            $request = $request->withHeaders(['anthropic-workspace-id' => (string) $workspace]);
        }

        try {
            $response = $request->get('/v1/models/'.rawurlencode((string) config('services.anthropic.models.chat')));
        } catch (Throwable $e) {
            Log::warning('Claude key check failed', ['error' => $e->getMessage()]);

            return $this->result('unknown', 'Claude API tidak bisa dihubungi');
        }

        return match (true) {
            $response->successful() => $this->result('ok', 'Kunci dan model chat diterima Claude API'),
            $response->status() === 401 => $this->result('bad', 'Kunci Claude ditolak (salah, dicabut, atau kedaluwarsa)'),
            $response->status() === 403 => $this->result('bad', 'Kunci Claude tidak punya izin atau workspace salah'),
            $response->status() === 404 => $this->result('bad', 'Model chat tidak ditemukan, periksa nama model di .env'),
            default => $this->result('unknown', 'Respons Claude API tak terduga ('.$response->status().')'),
        };
    }

    /**
     * API Status untuk pesanan yang tidak ada: 404 berarti kunci diterima dan mode cocok, 401 berarti kunci atau mode salah.
     * Midtrans kadang memberi kode di badan respons (status_code) dan HTTP 200, jadi keduanya dibaca.
     *
     * @return array{status: string, message: string}
     */
    private function midtrans(): array
    {
        if (! $this->midtrans->enabled()) {
            return $this->result('bad', 'Kunci Midtrans belum diisi');
        }

        try {
            $response = Http::withBasicAuth((string) config('services.midtrans.server_key'), '')
                ->acceptJson()->timeout(10)->connectTimeout(5)
                ->get($this->midtrans->statusApiUrl('tuffero-cek-kunci'));
        } catch (Throwable $e) {
            Log::warning('Midtrans key check failed', ['error' => $e->getMessage()]);

            return $this->result('unknown', 'Midtrans tidak bisa dihubungi');
        }

        $code = (int) ($response->json('status_code') ?? $response->status());

        return match (true) {
            $code === 404 => $this->result('ok', 'Kunci diterima Midtrans'),
            in_array($code, [401, 403], true) => $this->result('bad', 'Kunci ditolak Midtrans, cek server key dan mode sandbox atau produksi'),
            default => $this->result('unknown', "Respons Midtrans tak terduga ($code)"),
        };
    }

    /**
     * Daftar kurir adalah panggilan baca dan tidak menghitung tarif, jadi tidak memotong poin cek tarif (D-076).
     *
     * @return array{status: string, message: string}
     */
    private function biteship(): array
    {
        if (! filled(config('services.biteship.key'))) {
            return $this->result('bad', 'Kunci Biteship belum diisi');
        }

        try {
            $response = Http::baseUrl((string) config('services.biteship.base_url'))
                ->withHeaders(['Authorization' => (string) config('services.biteship.key')])
                ->acceptJson()->timeout(10)->connectTimeout(5)
                ->get('/v1/couriers');
        } catch (Throwable $e) {
            Log::warning('Biteship key check failed', ['error' => $e->getMessage()]);

            return $this->result('unknown', 'Biteship tidak bisa dihubungi');
        }

        return match (true) {
            $response->successful() && $this->hasCouriers($response) => $this->result('ok', 'Kunci diterima Biteship'),
            in_array($response->status(), [401, 403], true) => $this->result('bad', 'Kunci ditolak Biteship, cek API key'),
            default => $this->result('unknown', 'Respons Biteship tak terduga ('.$response->status().')'),
        };
    }

    private function hasCouriers(Response $response): bool
    {
        return $response->json('success') === true;
    }

    /** @return array{status: string, message: string} */
    private function result(string $status, string $message): array
    {
        return ['status' => $status, 'message' => $message];
    }
}
