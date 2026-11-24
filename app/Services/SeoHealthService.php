<?php

namespace App\Services;

use App\Models\Product;
use App\Support\StoreSite;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Kesehatan SEO untuk dashboard (Super Admin dan Pemilik, izin reports.view).
 * Kelengkapan konten dibaca dari database. Pemeriksaan teknis dan pratinjau membuka halaman publik situs sendiri:
 * alamat dasarnya selalu APP_URL dan jalurnya hanya dari daftar halaman yang dibuat server, tidak pernah dari masukan pengguna.
 */
class SeoHealthService
{
    public const CACHE_KEY = 'seo:technical';

    private const TIMEOUT_SECONDS = 8;

    /** Label halaman statis; urutan dan isinya mengikuti StoreSite::STATIC_PAGES (yang juga dipakai sitemap). */
    private const STATIC_LABELS = [
        '/' => 'Beranda',
        '/tentang-kami' => 'Tentang Kami',
        '/layanan' => 'Layanan',
        '/produk' => 'Katalog produk',
        '/galeri' => 'Galeri',
        '/kontak' => 'Kontak',
    ];

    /** Satu butir kelengkapan produk bernilai 20 poin. */
    private const CONTENT_CHECKS = [
        'description_id' => ['label' => 'Deskripsi Indonesia', 'issue' => 'Deskripsi Indonesia kosong'],
        'description_en' => ['label' => 'Deskripsi Inggris', 'issue' => 'Deskripsi Inggris kosong'],
        'photos' => ['label' => 'Minimal 3 foto', 'issue' => 'Foto kurang dari 3'],
        'category' => ['label' => 'Kategori aktif', 'issue' => 'Belum punya kategori aktif'],
        'specs' => ['label' => 'Berat dan masa simpan', 'issue' => 'Berat atau masa simpan belum diisi'],
    ];

    public function __construct(private StoreCatalogService $catalog) {}

    /**
     * Kelengkapan konten tiap produk tayang, dari yang paling perlu dilengkapi.
     *
     * @return array{summary: array<string, mixed>, products: list<array<string, mixed>>}
     */
    public function content(): array
    {
        $rows = $this->catalog->publishedProducts()->map(function (Product $product) {
            $checks = [
                'description_id' => trim((string) $product->description) !== '',
                'description_en' => trim((string) $product->description_en) !== '',
                'photos' => $product->images->count() >= 3,
                'category' => $product->category?->is_active === true,
                'specs' => $product->variants->every(fn ($variant) => $variant->weight_gram && $variant->shelf_life_days),
            ];

            return [
                'id' => $product->public_id,
                'path' => '/produk/'.strtolower((string) $product->variants->first()?->sku),
                'name' => $product->name,
                'category' => $product->category?->name_id,
                'photos' => $product->images->count(),
                'variants' => $product->variants->count(),
                'description_length' => mb_strlen(trim((string) $product->description)),
                'checks' => $checks,
                'score' => count(array_filter($checks)) * 20,
                'issues' => array_map(fn (string $key) => self::CONTENT_CHECKS[$key]['issue'], array_keys(array_filter($checks, fn (bool $ok) => ! $ok))),
            ];
        })->sortBy([['score', 'asc'], ['name', 'asc']])->values();

        $total = $rows->count();

        return [
            'summary' => [
                'total' => $total,
                'ready' => $rows->where('score', 100)->count(),
                'average_score' => $total ? (int) round($rows->avg('score')) : 0,
                'checks' => collect(self::CONTENT_CHECKS)->map(fn (array $check, string $key) => [
                    'key' => $key,
                    'label' => $check['label'],
                    'done' => $rows->filter(fn (array $row) => $row['checks'][$key])->count(),
                ])->values()->all(),
            ],
            'products' => $rows->all(),
        ];
    }

    /**
     * Halaman publik yang boleh dipratinjau: statis, kategori yang punya halaman, dan produk tayang.
     *
     * @return Collection<int, array{path: string, label: string, type: string}>
     */
    public function pages(): Collection
    {
        $static = collect(StoreSite::STATIC_PAGES)->map(fn (string $path) => ['path' => $path, 'label' => self::STATIC_LABELS[$path] ?? $path, 'type' => 'static']);
        $categories = $this->catalog->categories()->whereIn('slug', StoreSite::CATEGORY_PAGES)
            ->map(fn (array $category) => ['path' => '/kategori/'.$category['slug'], 'label' => 'Kategori '.$category['name']['id'], 'type' => 'category']);
        $products = $this->catalog->items()->map(fn (array $item) => ['path' => (string) $item['path'], 'label' => (string) $item['name']['id'], 'type' => 'product']);

        return $static->concat($categories)->concat($products)->values();
    }

    /**
     * Pratinjau satu halaman publik apa adanya (judul, deskripsi, gambar, canonical, robots), atau null bila jalurnya tidak ada di daftar.
     *
     * @return array<string, mixed>|null
     */
    public function preview(string $path, string $lang): ?array
    {
        $page = $this->pages()->firstWhere('path', $path);
        if (! $page) {
            return null;
        }

        $url = $this->base().$path.($lang === 'en' ? '?lang=en' : '');

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->withoutRedirecting()->withUserAgent('Tuffero-SEO-Check')->get($url);
        } catch (ConnectionException) {
            return [...$page, 'url' => $url, 'available' => false, 'status' => 0, 'issues' => ['Halaman tidak bisa dibuka dari server.']];
        }

        if (! $response->successful()) {
            return [...$page, 'url' => $url, 'available' => false, 'status' => $response->status(), 'issues' => ['Halaman menjawab status '.$response->status().'.']];
        }

        $meta = $this->parse($response->body());
        $noindex = $this->isNoindex($response, $meta['robots']);
        $titleLength = mb_strlen($meta['title']);
        $descriptionLength = mb_strlen($meta['description']);

        $issues = [];
        if ($titleLength === 0) {
            $issues[] = 'Judul kosong.';
        } elseif ($titleLength > 60) {
            $issues[] = 'Judul lebih dari 60 karakter, bisa terpotong di hasil Google.';
        } elseif ($titleLength < 30) {
            $issues[] = 'Judul kurang dari 30 karakter, kurang menjelaskan isi halaman.';
        }
        if ($descriptionLength === 0) {
            $issues[] = 'Deskripsi meta kosong.';
        } elseif ($descriptionLength > 160) {
            $issues[] = 'Deskripsi lebih dari 160 karakter, bisa terpotong.';
        } elseif ($descriptionLength < 70) {
            $issues[] = 'Deskripsi kurang dari 70 karakter, kurang menjelaskan isi halaman.';
        }
        if ($meta['og_image'] === '') {
            $issues[] = 'Tidak ada gambar pratinjau (Open Graph).';
        }
        if ($meta['canonical'] === '') {
            $issues[] = 'Tidak ada alamat canonical.';
        }
        if ($noindex) {
            $issues[] = 'Halaman ini noindex: tidak akan masuk hasil pencarian.';
        }

        return [
            ...$page,
            'url' => $url,
            'available' => true,
            'status' => $response->status(),
            'title' => $meta['title'],
            'title_length' => $titleLength,
            'description' => $meta['description'],
            'description_length' => $descriptionLength,
            'canonical' => $meta['canonical'],
            'og_image' => $meta['og_image'],
            'h1' => $meta['h1'],
            'json_ld' => $meta['json_ld'],
            'indexable' => ! $noindex,
            'issues' => $issues,
        ];
    }

    /** Hasil pemeriksaan teknis terakhir (disimpan), atau null bila belum pernah dijalankan. */
    public function technical(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    /**
     * Jalankan pemeriksaan teknis ke situs sendiri lalu simpan hasilnya. Satu kegagalan sambungan tidak menggagalkan yang lain.
     *
     * @return array<string, mixed>
     */
    public function runTechnical(): array
    {
        $base = $this->base();
        $environment = (string) config('app.env');
        $production = $environment === 'production';
        $sku = $this->catalog->items()->first()['sku'] ?? null;

        $responses = Http::pool(fn (Pool $pool) => array_values(array_filter([
            $pool->as('home')->timeout(self::TIMEOUT_SECONDS)->get($base.'/'),
            $pool->as('robots')->timeout(self::TIMEOUT_SECONDS)->get($base.'/robots.txt'),
            $pool->as('sitemap')->timeout(self::TIMEOUT_SECONDS)->get($base.'/sitemap.xml'),
            $pool->as('index')->timeout(self::TIMEOUT_SECONDS)->withoutRedirecting()->get($base.'/index.php'),
            $sku ? $pool->as('upper')->timeout(self::TIMEOUT_SECONDS)->withoutRedirecting()->get($base.'/produk/'.strtoupper($sku)) : null,
            $pool->as('missing')->timeout(self::TIMEOUT_SECONDS)->withoutRedirecting()->get($base.'/halaman-tidak-ada-cek-seo'),
        ])));

        $get = fn (string $key): ?Response => ($responses[$key] ?? null) instanceof Response ? $responses[$key] : null;
        $home = $get('home');
        $meta = $home ? $this->parse($home->body()) : null;
        $noindex = $home && $meta ? $this->isNoindex($home, $meta['robots']) : null;

        $checks = [
            $this->check('https', 'Alamat situs memakai HTTPS', str_starts_with($base, 'https://') ? 'ok' : ($production ? 'bad' : 'warn'),
                str_starts_with($base, 'https://') ? 'APP_URL memakai https.' : 'APP_URL ('.$base.') belum https. Canonical dan sitemap akan memakai alamat ini.'),
            $this->check('home', 'Beranda dapat dibuka', $home && $home->successful() ? 'ok' : 'bad',
                $home ? 'Status '.$home->status().'.' : 'Server tidak bisa membuka '.$base.'/ dari dalam. Periksa APP_URL dan jaringan.'),
            $this->indexableCheck($noindex, $production),
            $this->canonicalCheck($meta, $base),
            $this->robotsCheck($get('robots')),
            $this->sitemapCheck($get('sitemap'), $base),
            $this->duplicateCheck($get('index'), $sku ? $get('upper') : false),
            $this->check('not_found', 'Halaman yang tidak ada menjawab 404', ($get('missing')?->status() === 404) ? 'ok' : (($get('missing')) ? 'bad' : 'warn'),
                $get('missing') ? 'Status '.$get('missing')->status().($get('missing')->status() === 404 ? '.' : ' (seharusnya 404, kalau tidak Google menganggapnya halaman biasa).') : 'Tidak bisa diperiksa.'),
        ];

        $result = [
            'checked_at' => now()->toIso8601String(),
            'base_url' => $base,
            'environment' => $environment,
            'status' => collect(['bad', 'warn', 'unknown'])->first(fn (string $level) => collect($checks)->contains('status', $level)) ?? 'ok',
            'checks' => $checks,
        ];
        Cache::put(self::CACHE_KEY, $result, now()->addDays(7));

        return $result;
    }

    private function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** @return array{key: string, title: string, status: string, message: string} */
    private function check(string $key, string $title, string $status, string $message): array
    {
        return compact('key', 'title', 'status', 'message');
    }

    /** Halaman dianggap noindex bila header X-Robots-Tag atau meta robots memuatnya. */
    private function isNoindex(Response $response, string $metaRobots): bool
    {
        return str_contains(strtolower($response->header('X-Robots-Tag').' '.$metaRobots), 'noindex');
    }

    /** @return array{key: string, title: string, status: string, message: string} */
    private function indexableCheck(?bool $noindex, bool $production): array
    {
        $title = 'Boleh diindeks Google';
        if ($noindex === null) {
            return $this->check('indexable', $title, 'unknown', 'Tidak bisa diperiksa karena beranda tidak terbuka.');
        }
        if ($production) {
            return $noindex
                ? $this->check('indexable', $title, 'bad', 'Situs produksi memakai noindex: Google tidak akan memuatnya. Hapus sebelum rilis.')
                : $this->check('indexable', $title, 'ok', 'Beranda boleh diindeks.');
        }

        return $noindex
            ? $this->check('indexable', $title, 'ok', 'Lingkungan non-produksi memakai noindex, ini yang diharapkan.')
            : $this->check('indexable', $title, 'warn', 'Lingkungan non-produksi tidak memakai noindex: Google bisa mengindeksnya. Pasang noindex.');
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @return array{key: string, title: string, status: string, message: string}
     */
    private function canonicalCheck(?array $meta, string $base): array
    {
        $title = 'Canonical beranda sesuai alamat situs';
        if ($meta === null) {
            return $this->check('canonical', $title, 'unknown', 'Tidak bisa diperiksa karena beranda tidak terbuka.');
        }
        if ($meta['canonical'] === '') {
            return $this->check('canonical', $title, 'bad', 'Beranda tidak punya tag canonical.');
        }

        return parse_url($meta['canonical'], PHP_URL_HOST) === parse_url($base, PHP_URL_HOST)
            ? $this->check('canonical', $title, 'ok', $meta['canonical'])
            : $this->check('canonical', $title, 'bad', 'Canonical ('.$meta['canonical'].') berbeda dari alamat situs ('.$base.').');
    }

    /** @return array{key: string, title: string, status: string, message: string} */
    private function robotsCheck(?Response $response): array
    {
        $title = 'robots.txt';
        if (! $response || ! $response->successful()) {
            return $this->check('robots', $title, $response ? 'bad' : 'warn', $response ? 'robots.txt menjawab status '.$response->status().'.' : 'Tidak bisa diperiksa.');
        }

        $body = $response->body();
        if (preg_match('/^\s*Disallow:\s*\/\s*$/mi', $body)) {
            return $this->check('robots', $title, 'bad', 'robots.txt melarang seluruh situs (Disallow: /).');
        }

        return preg_match('/^\s*Sitemap:\s*\S+/mi', $body)
            ? $this->check('robots', $title, 'ok', 'Ada dan menyebut alamat sitemap.')
            : $this->check('robots', $title, 'warn', 'Ada, tetapi tidak menyebut alamat sitemap.');
    }

    /** @return array{key: string, title: string, status: string, message: string} */
    private function sitemapCheck(?Response $response, string $base): array
    {
        $title = 'sitemap.xml';
        if (! $response || ! $response->successful()) {
            return $this->check('sitemap', $title, $response ? 'bad' : 'warn', $response ? 'sitemap.xml menjawab status '.$response->status().'.' : 'Tidak bisa diperiksa.');
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->body());
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            return $this->check('sitemap', $title, 'bad', 'sitemap.xml bukan XML yang valid.');
        }

        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $locs = array_map('strval', $xml->xpath('//s:url/s:loc') ?: []);
        if ($locs === []) {
            return $this->check('sitemap', $title, 'bad', 'sitemap.xml tidak memuat satu pun URL.');
        }

        $host = parse_url($base, PHP_URL_HOST);
        $foreign = count(array_filter($locs, fn (string $loc) => parse_url($loc, PHP_URL_HOST) !== $host));

        return $foreign === 0
            ? $this->check('sitemap', $title, 'ok', count($locs).' URL, semuanya di '.$host.'.')
            : $this->check('sitemap', $title, 'warn', count($locs).' URL, tetapi '.$foreign.' di antaranya bukan di '.$host.'.');
    }

    /** @return array{key: string, title: string, status: string, message: string} */
    private function duplicateCheck(?Response $index, Response|false|null $upper): array
    {
        $title = 'Alamat ganda dialihkan permanen';
        if (! $index || $upper === null) {
            return $this->check('duplicates', $title, 'warn', 'Tidak bisa diperiksa.');
        }

        $problems = [];
        if ($index->status() !== 301) {
            $problems[] = '/index.php menjawab '.$index->status().' (seharusnya 301)';
        }
        if ($upper !== false && $upper->status() !== 301) {
            $problems[] = 'alamat produk huruf besar menjawab '.$upper->status().' (seharusnya 301)';
        }

        return $problems === []
            ? $this->check('duplicates', $title, 'ok', '/index.php dan alamat produk huruf besar dialihkan 301.')
            : $this->check('duplicates', $title, 'warn', ucfirst(implode('; ', $problems)).'.');
    }

    /**
     * @return array{title: string, description: string, canonical: string, robots: string, og_image: string, h1: string, json_ld: list<string>}
     */
    private function parse(string $html): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $x = new DOMXPath($doc);

        $types = [];
        foreach ($x->query('//script[@type="application/ld+json"]') as $script) {
            $data = json_decode($script->textContent, true);
            foreach (($data['@graph'] ?? [$data]) as $node) {
                if (is_array($node) && isset($node['@type'])) {
                    $types[] = is_array($node['@type']) ? implode('/', $node['@type']) : (string) $node['@type'];
                }
            }
        }

        $ogImage = trim((string) $x->evaluate('string(//meta[@property="og:image"]/@content)'));

        return [
            'title' => trim((string) $x->evaluate('string(//title)')),
            'description' => trim((string) $x->evaluate('string(//meta[@name="description"]/@content)')),
            'canonical' => trim((string) $x->evaluate('string(//link[@rel="canonical"]/@href)')),
            'robots' => trim((string) $x->evaluate('string(//meta[@name="robots"]/@content)')),
            // Hanya http(s): nilai ini dipakai sebagai alamat gambar di dashboard.
            'og_image' => preg_match('#^https?://#i', $ogImage) ? $ogImage : '',
            'h1' => trim(preg_replace('/\s+/u', ' ', (string) $x->evaluate('string(//h1)'))),
            'json_ld' => array_values(array_unique($types)),
        ];
    }
}
