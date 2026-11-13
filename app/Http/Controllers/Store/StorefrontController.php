<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\GalleryPhoto;
use App\Models\Order;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\PromoContent;
use App\Models\Setting;
use App\Models\TermsSection;
use App\Services\MidtransService;
use App\Services\NewsService;
use App\Services\StoreCatalogService;
use App\Services\TermsService;
use App\Support\StoreSite;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Halaman toko Blade (D-053). Pengendali tipis: harga, stok, dan aturan bisnis tetap di service dan API yang ada.
 * Judul memakai teks meta yang sama dengan versi Vue; deskripsi memakai teks halaman yang sudah ada, bukan teks baru.
 */
class StorefrontController extends Controller
{
    public function __construct(private StoreCatalogService $catalog, private StoreSite $site, private NewsService $news) {}

    public function home(Request $request): View
    {
        $t = trans('store.landing');
        $items = $this->catalog->items()->keyBy('sku');
        $pick = fn (array $rows, array $photos) => collect($rows)
            ->map(fn (array $row, int $i) => ['label' => $row[0], 'item' => $items->get($row[1]), 'photo' => $photos[$i]])
            ->filter(fn (array $row) => $row['item'] !== null)
            ->values();
        $offers = $pick($t['offers']['items'], ['Pia premium', 'Cookies', 'Meringue', 'Selai', 'Dubai', 'macaron regular']);

        $google = $this->catalog->reviews();

        return view('store.pages.home', [
            'seo' => ['title' => $t['meta']['title'], 'description' => $t['meta']['description'], 'jsonld' => $this->site->businessJsonLd($items->pluck('price')->all())],
            'google' => ['rating' => number_format($google['rating'], 1, ',', ''), 'count' => $google['count']],
            // Popup berita (D-085): data saja; JS membangun popup setelah halaman siap.
            'newsPopup' => $this->news->popup(app()->getLocale()),
            'featured' => $pick($t['products']['items'], ['Nastar Sultan', 'Macaron isi 30', 'Dubai', 'Nastar Apel']),
            'offerColumns' => [$offers->slice(0, 3)->values(), $offers->slice(3)->values()],
            // shortcut: bila katalog kosong, jumlah memakai data SKU lokal; ganti bila ada sumber jumlah resmi.
            'productTotal' => $items->count() ?: count(config('store_catalog.meta')),
            'welcome' => $this->welcome($request, $t['hero']['welcome']['greet']),
        ]);
    }

    /**
     * Sapaan hero untuk pelanggan yang sudah masuk: salam sesuai jam WIB (gudang Malang), nama depan, dan pesanan aktif terakhir.
     * Staf dan tamu mendapat null sehingga hero tampil seperti biasa; mesin pencari selalu melihat versi tamu.
     *
     * @param  array<string, string>  $greet
     * @return array{greeting: string, name: string, initials: string, order: ?Order}|null
     */
    private function welcome(Request $request, array $greet): ?array
    {
        $user = $request->user();
        if (! $user || $user->roles()->exists()) {
            return null;
        }

        $hour = now('Asia/Jakarta')->hour;
        $words = preg_split('/\s+/', trim($user->name), -1, PREG_SPLIT_NO_EMPTY) ?: [''];

        return [
            'greeting' => $greet[match (true) {
                $hour >= 4 && $hour < 11 => 'morning', $hour >= 11 && $hour < 15 => 'midday', $hour >= 15 && $hour < 18 => 'afternoon', default => 'night'
            }],
            'name' => $words[0],
            'initials' => collect($words)->take(2)->map(fn (string $w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode(''),
            'order' => $user->orders()
                ->whereIn('status', [Order::PENDING_PAYMENT, Order::PAID, Order::PROCESSING, Order::SHIPPED])
                ->latest('id')
                ->first(['public_ref', 'order_number', 'status']),
        ];
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

        // Foto dan video Galeri dikelola Marketing di dashboard (D-042, D-057, D-083).
        return view('store.pages.gallery', ['p' => $p, 'photos' => GalleryPhoto::liveList(), 'liveVideos' => PromoContent::liveVideos(), 'seo' => ['title' => $p['meta'], 'description' => $p['hero']['text'], 'image' => $this->site->productPhoto('Macaron isi 50 3')]]);
    }

    public function contact(): View
    {
        $p = trans('store.pages.contact');
        $terms = $this->terms($p);

        return view('store.pages.contact', ['p' => $p, 'terms' => $terms, 'status' => $this->site->openStatus(), 'seo' => ['title' => $p['meta'], 'description' => $p['hero']['text'], 'image' => $this->site->productPhoto('Nastar Classic 5')]]);
    }

    /**
     * S&K dari dashboard (butir tayang, urut). Tanpa butir tayang, teks bawaan di berkas bahasa dipakai. Versi dan tanggalnya ditampilkan
     * supaya jelas teks mana yang disetujui pelanggan saat mengajukan refund.
     *
     * @param  array<string, mixed>  $p
     * @return array{version: string, date: string}|null
     */
    private function terms(array &$p): ?array
    {
        $sections = TermsSection::published()->get();

        if ($sections->isEmpty()) {
            return null;
        }

        $en = app()->getLocale() === 'en';
        $p['legal']['docs'][0]['items'] = $sections->map(fn (TermsSection $s) => $en ? [$s->title_en, $s->body_en] : [$s->title_id, $s->body_id])->all();

        $updated = Setting::where('key', TermsService::VERSION_KEY)->value('updated_at');

        return [
            'version' => app(TermsService::class)->version(),
            'date' => ($updated ? Carbon::parse($updated) : now())->locale($en ? 'en' : 'id')->translatedFormat('j F Y'),
        ];
    }

    public function catalog(): View
    {
        $c = trans('store.pages.catalog');

        return view('store.pages.catalog', ['c' => $c, 'items' => $this->catalog->items(), 'categories' => $this->catalog->categories(), 'seo' => ['title' => $c['meta'], 'description' => $c['lead'], 'image' => $this->site->productPhoto('Nastar Classic 3')]]);
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

        // Ulasan pembeli untuk produk ini (semua varian), terpisah dari ulasan toko.
        $productId = ProductVariant::where('sku', $item['sku'])->value('product_id');
        $buyerReviews = ProductReview::where('product_id', $productId)->where('is_published', true);
        $buyer = [
            'count' => (clone $buyerReviews)->count(),
            'rating' => round((float) (clone $buyerReviews)->avg('rating'), 1),
            'items' => $buyerReviews->latest('id')->limit(10)->get(['author_name', 'rating', 'body']),
        ];

        return response()->view('store.pages.product', [
            'c' => $c, 'p' => $p, 'x' => $item, 'description' => $description, 'picks' => $picks, 'also' => $also, 'reviews' => $this->catalog->reviews(), 'buyer' => $buyer,
            'seo' => [
                'title' => $item['name'][$locale].$p['metaSuffix'],
                'description' => $description,
                'image' => $photo,
                'type' => 'product',
                'jsonld' => [
                    '@context' => 'https://schema.org',
                    '@graph' => [
                        array_filter([
                            '@type' => 'Product',
                            'name' => $item['name'][$locale],
                            'sku' => $item['sku'],
                            'description' => $description,
                            'image' => $photo ? url($photo) : null,
                            'brand' => ['@type' => 'Brand', 'name' => 'Tuffero'],
                            'offers' => [
                                '@type' => 'Offer', 'priceCurrency' => 'IDR', 'price' => $item['price'], 'url' => url($item['path']),
                                'availability' => $item['in_stock'] ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                                'hasMerchantReturnPolicy' => $this->site->returnPolicyJsonLd(),
                            ],
                        ]),
                        [
                            '@type' => 'BreadcrumbList',
                            'itemListElement' => [
                                ['@type' => 'ListItem', 'position' => 1, 'name' => $p['home'], 'item' => url('/')],
                                ['@type' => 'ListItem', 'position' => 2, 'name' => $p['products'], 'item' => url('/produk')],
                                ['@type' => 'ListItem', 'position' => 3, 'name' => $item['name'][$locale]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function cart(): View
    {
        $checkout = trans('store.pages.checkout');
        $c = [
            ...trans('store.pages.voucherCard'),
            ...trans('store.pages.cart'),
            'voucher' => $checkout['voucher'],
            'errors' => $checkout['errors'],
            'tooMany' => $checkout['tooMany'],
        ];

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
    public function order(string $ref, MidtransService $midtrans): View
    {
        $c = trans('store.pages.order');
        // Client key Midtrans memang publik (dipakai snap.js); server key tidak pernah ke browser.
        $pay = $midtrans->enabled() ? ['clientKey' => config('services.midtrans.client_key'), 'snapJs' => $midtrans->snapJsUrl()] : null;

        return view('store.pages.order', ['c' => $c, 'ref' => $ref, 'pay' => $pay, 'seo' => ['title' => $c['meta'], 'noindex' => true]]);
    }

    public function lookup(): View
    {
        $c = trans('store.pages.lookup');

        return view('store.pages.lookup', ['c' => $c, 'seo' => ['title' => $c['meta'], 'noindex' => true]]);
    }

    public function refund(): View
    {
        $c = trans('store.pages.refund');

        // Halaman alur pribadi (data pesanan): tidak diindeks dan tidak masuk sitemap.
        return view('store.pages.refund', ['c' => $c, 'seo' => ['title' => $c['meta'], 'noindex' => true]]);
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

    /** Daftar kampanye yang sedang berjalan atau akan datang (D-073). */
    public function promos(): View
    {
        $c = trans('store.pages.promo');
        $campaigns = Campaign::visible()->orderBy('starts_at')->get();

        return view('store.pages.promos', [
            'c' => $c,
            'campaigns' => $campaigns,
            'seo' => ['title' => $c['meta'], 'description' => $c['description']],
        ]);
    }

    public function promo(Request $request, string $slug): View|Response
    {
        $campaign = Campaign::visible()->where('slug', $slug)->first();
        if (! $campaign) {
            return $this->missing($request);
        }

        $c = [...trans('store.pages.promo'), 'claim' => [...trans('store.pages.voucherCard'), ...trans('store.pages.vouchers')]];

        return view('store.pages.promo', [
            'c' => $c,
            'campaign' => $campaign,
            'live' => $campaign->starts_at->lte(now()),
            'items' => $this->catalog->campaignItems($campaign),
            'seo' => ['title' => $campaign->title.$c['metaSuffix'], 'description' => $campaign->description ?: $c['description']],
        ]);
    }

    public function vouchers(): View
    {
        $c = [...trans('store.pages.voucherCard'), ...trans('store.pages.vouchers')];

        return view('store.pages.vouchers', ['c' => $c, 'seo' => ['title' => $c['meta'], 'description' => $c['description']]]);
    }

    public function accountVouchers(): View
    {
        $c = [...trans('store.pages.voucherCard'), ...trans('store.pages.accountVouchers')];

        return view('store.pages.account-vouchers', ['c' => $c, 'seo' => ['title' => $c['meta'], 'noindex' => true]]);
    }

    public function accountAddresses(): View
    {
        $c = trans('store.pages.accountAddresses');

        return view('store.pages.account-addresses', ['c' => $c, 'seo' => ['title' => $c['meta'], 'noindex' => true]]);
    }

    public function sitemap(): Response
    {
        // lastmod hanya untuk halaman yang punya tanggal ubah di database; halaman statis tanpa lastmod.
        $urls = collect(['/', '/tentang-kami', '/layanan', '/produk', '/promo', '/voucher', '/galeri', '/kontak'])
            ->map(fn (string $path) => ['path' => $path, 'lastmod' => null])
            ->merge(Campaign::visible()->get(['slug', 'updated_at'])->map(fn (Campaign $campaign) => ['path' => '/promo/'.$campaign->slug, 'lastmod' => $campaign->updated_at]))
            ->merge($this->catalog->publishedProducts()->flatMap(fn ($product) => $product->variants->map(fn ($variant) => [
                'path' => '/produk/'.strtolower($variant->sku),
                'lastmod' => max($product->updated_at, $variant->updated_at),
            ])));

        return response()->view('store.sitemap', ['urls' => $urls])->header('Content-Type', 'application/xml');
    }

    public function missing(Request $request): Response
    {
        // Alamat API yang tidak ada tetap menjawab JSON.
        abort_if($request->is('api/*'), 404);

        $p = trans('store.pages.product');

        return response()->view('store.pages.not-found', ['seo' => ['title' => trans('errors.404.title').$p['metaSuffix'], 'noindex' => true]], 404);
    }

    /**
     * Deskripsi dari dashboard per bahasa (D-065); bila kosong dipakai ringkasan dari data.
     *
     * @param  array<string, mixed>  $x
     * @param  array<string, mixed>  $p
     */
    private function productDescription(array $x, array $p, string $locale): string
    {
        $written = $locale === 'id' ? $x['description'] : $x['description_en'];
        if ($written) {
            return $written;
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
