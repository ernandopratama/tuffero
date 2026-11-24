<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Checklist peluncuran SEO di dashboard. Daftar langkah ada di kode; yang sudah dicentang disimpan di tabel settings
 * (kunci `seo.checklist.<kunci>`, nilai JSON, tidak publik). Langkah `auto` dihitung dari data, tidak bisa dicentang tangan.
 */
class SeoChecklist
{
    private const PREFIX = 'seo.checklist.';

    /** @var array<string, array{title: string, detail: string, auto?: bool}> */
    public const ITEMS = [
        'domain-live' => ['title' => 'Aplikasi tayang di tuffero.com', 'detail' => 'Halaman "Segera Hadir" sudah diganti aplikasi dan HTTPS aktif.'],
        'search-console' => ['title' => 'Properti tuffero.com terverifikasi di Search Console', 'detail' => 'Verifikasi domain lewat DNS, oleh akun Google milik Super Admin.'],
        'sitemap-submitted' => ['title' => 'Sitemap dikirim di Search Console', 'detail' => 'Kirim alamat sitemap.xml produksi dan pastikan statusnya berhasil dibaca.'],
        'robots-checked' => ['title' => 'robots.txt akhir diperiksa', 'detail' => 'Pastikan robots Cloudflare tidak menimpa robots aplikasi dan kebijakan crawler sesuai keputusan.'],
        'brand-name' => ['title' => 'Nama merek diputuskan dan diseragamkan', 'detail' => 'Situs, Google Maps, TikTok, dan Instagram memakai nama yang sama.'],
        'business-profile' => ['title' => 'Google Business Profile diklaim dan sesuai situs', 'detail' => 'Alamat, jam buka, nomor, dan kategori sama dengan yang ada di situs.'],
        'sample-data' => ['title' => 'Data contoh tidak ada di produksi', 'detail' => 'Promo, voucher, dan ulasan contoh sudah dihapus atau tidak pernah dimuat.'],
        'product-descriptions' => ['title' => 'Semua produk punya deskripsi Indonesia dan Inggris', 'detail' => 'Dihitung otomatis dari data produk.', 'auto' => true],
        'rich-results' => ['title' => 'Rich Results Test dengan URL asli lolos', 'detail' => 'Uji beranda, katalog, satu kategori, dan satu produk.'],
        'pagespeed' => ['title' => 'PageSpeed Insights URL asli dijalankan', 'detail' => 'Catat LCP, INP, dan CLS beranda dan halaman produk.'],
    ];

    /**
     * @param  array<string, mixed>  $contentSummary  hasil SeoHealthService::content()['summary']
     * @param  array<string, bool>  $confirmed  langkah yang dikonfirmasi data Google (SeoSyncService::confirmations())
     * @return list<array<string, mixed>>
     */
    public function items(array $contentSummary, array $confirmed = []): array
    {
        $saved = Setting::whereIn('key', array_map(fn (string $key) => self::PREFIX.$key, array_keys(self::ITEMS)))->pluck('value', 'key');
        $total = (int) ($contentSummary['total'] ?? 0);
        $described = collect($contentSummary['checks'] ?? [])->whereIn('key', ['description_id', 'description_en'])->min('done');

        return collect(self::ITEMS)->map(function (array $item, string $key) use ($saved, $total, $described, $confirmed) {
            $state = json_decode((string) ($saved[self::PREFIX.$key] ?? ''), true) ?: [];
            $auto = $item['auto'] ?? false;

            return [
                'key' => $key,
                'title' => $item['title'],
                'detail' => $item['detail'],
                'auto' => $auto,
                'confirmed' => (bool) ($confirmed[$key] ?? false),
                'done' => $auto ? ($total > 0 && (int) $described === $total) : ((bool) ($state['done'] ?? false) || (bool) ($confirmed[$key] ?? false)),
                'done_at' => $auto ? null : ($state['at'] ?? null),
                'done_by' => $auto ? null : ($state['by'] ?? null),
            ];
        })->values()->all();
    }

    /** Centang atau lepas satu langkah. Langkah otomatis dan kunci tak dikenal ditolak. */
    public function set(string $key, bool $done, User $actor): void
    {
        if (! isset(self::ITEMS[$key])) {
            abort(404);
        }
        if (self::ITEMS[$key]['auto'] ?? false) {
            throw ValidationException::withMessages(['done' => 'Langkah ini dihitung otomatis dari data produk.']);
        }

        $setting = Setting::updateOrCreate(['key' => self::PREFIX.$key], [
            'value' => json_encode($done ? ['done' => true, 'at' => now()->toIso8601String(), 'by' => $actor->name] : ['done' => false], JSON_THROW_ON_ERROR),
            'type' => 'json',
            'group' => 'seo',
            'is_public' => false,
            'updated_by_user_id' => $actor->id,
        ]);

        AuditLog::record('seo.checklist_updated', $setting, ['key' => $key, 'done' => $done], $actor->id);
    }
}
