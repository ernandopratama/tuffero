<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\StoreCatalogService;
use App\Support\StoreSite;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Halaman toko Blade (D-053). Pengendali tipis: harga, stok, dan aturan bisnis tetap di service dan API yang ada.
 * Judul memakai teks meta yang sama dengan versi Vue; deskripsi memakai teks halaman yang sudah ada, bukan teks baru.
 */
class StorefrontController extends Controller
{
    public function __construct(private StoreCatalogService $catalog, private StoreSite $site) {}

    public function home(): View
    {
        $t = trans('store.landing');
        $items = $this->catalog->items()->keyBy('sku');
        $pick = fn (array $rows, array $photos) => collect($rows)
            ->map(fn (array $row, int $i) => ['label' => $row[0], 'item' => $items->get($row[1]), 'photo' => $photos[$i]])
            ->filter(fn (array $row) => $row['item'] !== null)
            ->values();
        $offers = $pick($t['offers']['items'], ['Pia premium', 'Cookies', 'Meringue', 'Selai', 'Dubai', 'macaron regular']);

        return view('store.pages.home', [
            'seo' => ['title' => $t['meta']['title'], 'description' => $t['hero']['text']],
            'featured' => $pick($t['products']['items'], ['Nastar Sultan', 'Macaron isi 30', 'Dubai', 'Nastar Apel']),
            'offerColumns' => [$offers->slice(0, 3)->values(), $offers->slice(3)->values()],
            // shortcut: bila katalog kosong, jumlah memakai data SKU lokal; ganti bila ada sumber jumlah resmi.
            'productTotal' => $items->count() ?: count(config('store_catalog.meta')),
        ]);
    }

    public function about(): View
    {
        $p = trans('store.pages.about');

        return view('store.pages.about', ['p' => $p, 'seo' => ['title' => $p['meta'], 'description' => $p['hero']['text'], 'image' => $this->site->productPhoto('Nastar Classic 2')]]);
    }

    public function services(): View
    {
        $p = trans('store.pages.services');
        $items = $this->catalog->items()->keyBy('sku');
        $locale = app()->getLocale();

        // Poin { sku } mengambil nama dan harga dari katalog; produk yang ditarik dari toko tidak tampil.
        $rows = collect($p['rows'])->map(function (array $row) use ($items, $locale) {
            $row['points'] = collect($row['points'])->flatMap(function ($point) use ($items, $locale) {
                if (is_string($point)) {
                    return [$point];
                }
                $item = $items->get($point['sku']);

                return $item ? ["{$item['name'][$locale]}: {$this->site->rupiah($item['price'])}"] : [];
            })->all();

            return $row;
        })->all();

        return view('store.pages.services', ['p' => $p, 'rows' => $rows, 'seo' => ['title' => $p['meta'], 'description' => $p['hero']['text'], 'image' => $this->site->productPhoto('Macaron isi 50 2')]]);
    }

    public function gallery(): View
    {
        $p = trans('store.pages.gallery');

        return view('store.pages.gallery', ['p' => $p, 'seo' => ['title' => $p['meta'], 'description' => $p['hero']['text'], 'image' => $this->site->productPhoto('Macaron isi 50 3')]]);
    }

    public function contact(): View
    {
        $p = trans('store.pages.contact');

        return view('store.pages.contact', ['p' => $p, 'status' => $this->site->openStatus(), 'seo' => ['title' => $p['meta'], 'description' => $p['hero']['text'], 'image' => $this->site->productPhoto('Nastar Classic 5')]]);
    }

    public function catalog(): View
    {
        $c = trans('store.pages.catalog');

        return view('store.pages.catalog', ['c' => $c, 'items' => $this->catalog->items(), 'seo' => ['title' => $c['meta'], 'description' => $c['lead']]]);
    }

    public function product(string $sku): Response
    {
        $c = trans('store.pages.catalog');
        $p = trans('store.pages.product');
        $locale = app()->getLocale();
        $item = $this->catalog->find($sku);

        if (! $item) {
            return response()->view('store.pages.product-missing', [
                'c' => $c, 'p' => $p,
                'seo' => ['title' => $p['notFoundTitle'].$p['metaSuffix'], 'description' => $p['notFoundText'], 'noindex' => true],
            ], 404);
        }

        $description = $this->productDescription($item, $p, $locale);
        $items = $this->catalog->items();

        // Dua daftar tanpa produk yang sama: pilihan toko (samping) dan produk lain (kategori yang sama lebih dulu).
        $picks = collect(['NAS-TOP-01', 'MAC-MIN-31', 'CHO-DUB-01', 'NAS-APEL-01', 'PIA-OLH-02', 'MAC-REG-06'])
            ->map(fn (string $code) => $items->firstWhere('sku', $code))
            ->filter(fn ($row) => $row && $row['sku'] !== $item['sku'])
            ->take(5)
            ->values();
        $used = $picks->pluck('sku')->push($item['sku']);
        $rest = $items->reject(fn (array $row) => $used->contains($row['sku']));
        $also = $rest->where('cat', $item['cat'])->concat($rest->where('cat', '!==', $item['cat']))->take(12)->values();

        $photo = $item['photos'][0]['src'] ?? null;

        return response()->view('store.pages.product', [
            'c' => $c, 'p' => $p, 'x' => $item, 'description' => $description, 'picks' => $picks, 'also' => $also, 'reviews' => $this->catalog->reviews(),
            'seo' => [
                'title' => $item['name'][$locale].$p['metaSuffix'],
                'description' => $description,
                'image' => $photo,
                'type' => 'product',
                'jsonld' => array_filter([
                    '@context' => 'https://schema.org',
                    '@type' => 'Product',
                    'name' => $item['name'][$locale],
                    'sku' => $item['sku'],
                    'description' => $description,
                    'image' => $photo ? url($photo) : null,
                    'brand' => ['@type' => 'Brand', 'name' => 'Tuffero'],
                    'offers' => ['@type' => 'Offer', 'priceCurrency' => 'IDR', 'price' => $item['price'], 'url' => url($item['path'])],
                ]),
            ],
        ]);
    }

    public function cart(): View
    {
        $c = trans('store.pages.cart');

        return view('store.pages.cart', ['c' => $c, 'seo' => ['title' => $c['meta'], 'noindex' => true]]);
    }

    public function checkout(): View
    {
        $c = trans('store.pages.checkout');

        return view('store.pages.checkout', ['c' => $c, 'seo' => ['title' => $c['meta'], 'noindex' => true]]);
    }

    /**
     * Status pesanan tidak dirender server: token tamu ada di fragmen URL (D-050), jadi data diambil browser lewat API yang memeriksa aksesnya.
     */
    public function order(string $ref): View
    {
        $c = trans('store.pages.order');

        return view('store.pages.order', ['c' => $c, 'ref' => $ref, 'seo' => ['title' => $c['meta'], 'noindex' => true]]);
    }

    public function lookup(): View
    {
        $c = trans('store.pages.lookup');

        return view('store.pages.lookup', ['c' => $c, 'seo' => ['title' => $c['meta'], 'noindex' => true]]);
    }

    public function signIn(): View
    {
        return $this->authView('login');
    }

    public function register(): View
    {
        return $this->authView('register');
    }

    private function authView(string $mode): View
    {
        $a = trans('store.pages.auth');

        return view('store.pages.auth', ['a' => $a, 'mode' => $mode, 'seo' => ['title' => $a[$mode]['meta'], 'noindex' => true]]);
    }

    public function account(): View
    {
        $c = trans('store.pages.account');

        return view('store.pages.account', ['c' => $c, 'seo' => ['title' => $c['meta'], 'noindex' => true]]);
    }

    public function accountOrders(): View
    {
        $c = trans('store.pages.accountOrders');

        return view('store.pages.account-orders', ['c' => $c, 'seo' => ['title' => $c['meta'], 'noindex' => true]]);
    }

    public function accountAddresses(): View
    {
        $c = trans('store.pages.accountAddresses');

        return view('store.pages.account-addresses', ['c' => $c, 'seo' => ['title' => $c['meta'], 'noindex' => true]]);
    }

    public function sitemap(): Response
    {
        $urls = collect(['/', '/tentang-kami', '/layanan', '/produk', '/galeri', '/kontak'])
            ->merge($this->catalog->items()->pluck('path'));

        return response()->view('store.sitemap', ['urls' => $urls])->header('Content-Type', 'application/xml');
    }

    public function missing(Request $request): Response
    {
        // Alamat API yang tidak ada tetap menjawab JSON.
        abort_if($request->is('api/*'), 404);

        $p = trans('store.pages.product');

        return response()->view('store.pages.not-found', ['seo' => ['title' => $p['notFoundTitle'].$p['metaSuffix'], 'noindex' => true], 'p' => $p], 404);
    }

    /**
     * Deskripsi dari dashboard berbahasa Indonesia (OQ-16); halaman Inggris tetap memakai ringkasan dari data.
     *
     * @param  array<string, mixed>  $x
     * @param  array<string, mixed>  $p
     */
    private function productDescription(array $x, array $p, string $locale): string
    {
        if ($x['description'] && $locale === 'id') {
            return $x['description'];
        }

        $parts = [$this->site->fill($p['descPack'], ['pack' => $x['pack']])];
        if ($x['net']) {
            $parts[] = $this->site->fill($p['descNet'], ['net' => $x['net']]);
        }
        if ($x['shelf']) {
            $parts[] = $this->site->fill($p['descShelf'], ['shelf' => $this->site->shelfPlain($x)]);
        }

        return $x['name'][$locale].'. '.implode(', ', $parts).'.';
    }
}
