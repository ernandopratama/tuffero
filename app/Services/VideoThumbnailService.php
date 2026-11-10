<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\PromoContent;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Thumbnail otomatis video (D-042, D-058). Server hanya meminta alamat tetap yang dibuat dari ID video YouTube
 * (`img.youtube.com`). Tanpa pengalihan, batas waktu dan ukuran, hanya JPEG/PNG/WebP yang diurai ulang menjadi JPEG
 * dan disimpan di penyimpanan sendiri. TikTok dan Instagram tetap unggah manual: oEmbed TikTok tidak lagi memuat
 * `thumbnail_url` (diperiksa 6 Nov 2026) dan Instagram butuh token Meta.
 */
class VideoThumbnailService
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    private const YOUTUBE_HOST = 'img.youtube.com';

    public function supports(PromoContent $content): bool
    {
        return $content->type === 'video' && $content->provider === 'youtube' && $content->external_id;
    }

    /** Thumbnail yang diambil sistem (bukan unggahan admin) boleh diganti otomatis saat tautan berubah. */
    public function isAuto(PromoContent $content): bool
    {
        return str_contains((string) $content->image_path, '/auto-');
    }

    /** @return 'fetched'|'failed'|'unsupported' */
    public function fetch(PromoContent $content, User $actor): string
    {
        if (! $this->supports($content)) {
            return 'unsupported';
        }

        try {
            $binary = $this->youtube((string) $content->external_id);
            $jpeg = $this->normalize($binary);
        } catch (RuntimeException|ConnectionException $e) {
            // Tanpa URL dan isi balasan di log; cukup alasan singkat.
            Log::info('Thumbnail video tidak diambil.', ['content_id' => $content->id, 'provider' => $content->provider, 'reason' => $e::class === ConnectionException::class ? 'koneksi' : $e->getMessage()]);

            return 'failed';
        }

        $old = $content->image_path;
        $path = "promo/{$content->id}/auto-".Str::random(10).'.jpg';
        Storage::disk('public')->put($path, $jpeg);

        DB::transaction(function () use ($content, $path, $jpeg, $actor) {
            $content->forceFill(['image_path' => $path])->save();
            AuditLog::record('content.thumbnail_fetched', $content, ['provider' => $content->provider, 'bytes' => strlen($jpeg)], $actor->id);
        });

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return 'fetched';
    }

    private function youtube(string $id): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            throw new RuntimeException('ID YouTube tidak valid');
        }

        foreach (['maxresdefault', 'hqdefault'] as $name) {
            try {
                return $this->download('https://'.self::YOUTUBE_HOST."/vi/{$id}/{$name}.jpg");
            } catch (RuntimeException) {
                // Tidak ada resolusi penuh: coba yang lebih kecil.
            }
        }

        throw new RuntimeException('YouTube tidak punya thumbnail');
    }

    private function download(string $url): string
    {
        $parts = parse_url($url);

        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== self::YOUTUBE_HOST || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new RuntimeException('Host gambar tidak diizinkan');
        }

        $response = Http::timeout(5)->connectTimeout(3)->withoutRedirecting()->withOptions(['stream' => true])->get($url);

        if (! $response->successful() || (int) $response->header('Content-Length') > self::MAX_BYTES) {
            throw new RuntimeException('Gambar tidak tersedia atau terlalu besar');
        }

        $body = $response->toPsrResponse()->getBody();
        $data = '';
        while (! $body->eof() && strlen($data) <= self::MAX_BYTES) {
            $data .= $body->read(65536);
        }

        if ($data === '' || strlen($data) > self::MAX_BYTES) {
            throw new RuntimeException('Gambar kosong atau terlalu besar');
        }

        return $data;
    }

    /** Memeriksa dan menggambar ulang menjadi JPEG: membuang metadata dan muatan tersembunyi. */
    private function normalize(string $binary): string
    {
        $info = @getimagesizefromstring($binary);
        if (! $info || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('Bukan gambar JPEG, PNG, atau WebP');
        }

        [$width, $height] = $info;
        if ($width < 100 || $height < 100 || $width > 5000 || $height > 5000) {
            throw new RuntimeException('Dimensi gambar di luar batas');
        }

        $source = @imagecreatefromstring($binary);
        if (! $source) {
            throw new RuntimeException('Gambar tidak dapat dibaca');
        }

        $scale = min(1, 1920 / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $w, $h, $width, $height);

        ob_start();
        imagejpeg($canvas, null, 85);
        $jpeg = (string) ob_get_clean();

        if ($jpeg === '' || strlen($jpeg) > self::MAX_BYTES) {
            throw new RuntimeException('Hasil gambar terlalu besar');
        }

        return $jpeg;
    }
}
