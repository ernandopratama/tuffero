<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\HtmlString;

/**
 * Fakta dan pembantu tampilan bersama halaman toko (Blade, D-053). Sumber fakta: jawaban tim 2026-11-03 (OQ-21).
 */
class StoreSite
{
    public const TIKTOK_URL = 'https://www.tiktok.com/@tuffero.patisserie';

    public const TIKTOK_HANDLE = '@tuffero.patisserie';

    public const INSTAGRAM_URL = 'https://www.instagram.com/tuffero.bakery/';

    public const INSTAGRAM_HANDLE = '@tuffero.bakery';

    public const MAPS_URL = 'https://www.google.com/maps/place/Tuffero+Patisserie+(Rumah+Produksi+Macaron+Tuffero)/@-7.9266161,112.5846427,17z';

    public const MAP_LAT = -7.9266161;

    public const MAP_LNG = 112.5846427;

    public const MAPS_DIRECTIONS_URL = 'https://www.google.com/maps/dir/?api=1&destination=-7.9266161,112.5846427';

    public const MAPS_EMBED_URL = 'https://www.google.com/maps?q=-7.9266161,112.5846427&z=16&output=embed';

    public const RATING = '4,6';

    public const ROUTES = [
        'about' => '/tentang-kami', 'services' => '/layanan', 'products' => '/produk', 'offers' => '/#offers', 'gallery' => '/galeri', 'contact' => '/kontak',
        'account' => '/masuk', 'profile' => '/akun', 'orders' => '/akun/pesanan', 'addresses' => '/akun/alamat', 'vouchers' => '/voucher', 'promos' => '/promo', 'myVouchers' => '/akun/voucher', 'cart' => '/keranjang', 'checkout' => '/checkout', 'track' => '/cek-pesanan',
    ];

    public const NAV_LINKS = ['/', '/tentang-kami', '/layanan', '/produk', '/promo', '/#offers', '/galeri', '/kontak'];

    public const FOOTER_LINKS = ['/', '/tentang-kami', '/layanan', '/produk', '/#offers'];

    public const SERVICE_IDS = ['pesan-online', 'hampers', 'oleh-oleh', 'kolaborasi'];

    // Senin = indeks 0; null = tutup. Angka = jam, waktu Malang (WIB).
    public const OPEN_HOURS = [[9, 16], [9, 16], [9, 16], [9, 16], [9, 16], [9, 16], null];

    private const FALLBACK_WHATSAPP = '+6281231481755';

    private ?string $whatsapp = null;

    public function route(string $name): string
    {
        return self::ROUTES[$name];
    }

    /**
     * Satu nomor untuk telepon dan WhatsApp (OQ-24), dari contact.whatsapp_number di dashboard.
     */
    public function whatsappNumber(): string
    {
        return $this->whatsapp ??= (string) (Setting::where('is_public', true)->where('key', 'contact.whatsapp_number')->value('value') ?: self::FALLBACK_WHATSAPP);
    }

    private function whatsappDigits(): string
    {
        return (string) preg_replace('/\D/', '', $this->whatsappNumber());
    }

    public function phoneDisplay(): string
    {
        $number = $this->whatsappNumber();

        return str_starts_with($number, '+62')
            ? (string) preg_replace('/(\d{4})(?=\d)/', '$1-', '0'.substr($this->whatsappDigits(), 2))
            : $number;
    }

    public function phoneHref(): string
    {
        return 'tel:+'.$this->whatsappDigits();
    }

    public function whatsappUrl(): string
    {
        return 'https://wa.me/'.$this->whatsappDigits();
    }

    // Tuffero berdiri 8 Juni 2021. Tahun berkarya dihitung dari tanggal itu agar tidak basi.
    public function years(): int
    {
        $today = CarbonImmutable::now('Asia/Jakarta');

        return $today->year - 2021 - ($today->lt($today->setDate($today->year, 6, 8)->startOfDay()) ? 1 : 0);
    }

    /**
     * @return array{day: int, open: bool}
     */
    public function openStatus(): array
    {
        $now = CarbonImmutable::now('Asia/Jakarta');
        $day = $now->dayOfWeekIso - 1;
        $hours = self::OPEN_HOURS[$day];

        return ['day' => $day, 'open' => $hours !== null && $now->hour >= $hours[0] && $now->hour < $hours[1]];
    }

    /**
     * JSON-LD Bakery untuk beranda (local SEO). Hanya fakta yang sudah ada di StoreSite dan teks footer.
     * Tanpa aggregateRating: Google tidak menampilkan ulasan yang dipasang sendiri oleh bisnis lokal.
     *
     * @return array<string, mixed>
     */
    public function businessJsonLd(): array
    {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

        return [
            '@context' => 'https://schema.org',
            '@type' => 'Bakery',
            'name' => 'Tuffero Bakery',
            'url' => url('/'),
            'image' => url(str_replace(' ', '%20', $this->productPhoto('Nastar Classic 2'))),
            'telephone' => $this->whatsappNumber(),
            'foundingDate' => '2021-06-08',
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => trans('store.landing.footer.address', [], 'id'),
                'addressLocality' => 'Malang',
                'addressRegion' => 'Jawa Timur',
                'postalCode' => '65151',
                'addressCountry' => 'ID',
            ],
            'geo' => ['@type' => 'GeoCoordinates', 'latitude' => self::MAP_LAT, 'longitude' => self::MAP_LNG],
            'hasMap' => self::MAPS_URL,
            'openingHoursSpecification' => collect(self::OPEN_HOURS)
                ->map(fn (?array $hours, int $i) => $hours ? [
                    '@type' => 'OpeningHoursSpecification',
                    'dayOfWeek' => $days[$i],
                    'opens' => sprintf('%02d:00', $hours[0]),
                    'closes' => sprintf('%02d:00', $hours[1]),
                ] : null)
                ->filter()->values()->all(),
            'sameAs' => [self::INSTAGRAM_URL, self::TIKTOK_URL],
        ];
    }

    public function productPhoto(string $name): string
    {
        return "/images/Produk/{$name}_result.webp";
    }

    public function rupiah(int $amount): string
    {
        return 'Rp'.number_format($amount, 0, ',', '.');
    }

    /**
     * Persen hemat untuk label "-30%": dari harga coret ke harga bayar. 0 bila tidak ada potongan.
     *
     * @param  array<string, mixed>  $item
     */
    public function offPercent(array $item): int
    {
        return $item['normal'] > $item['price'] ? (int) round((1 - $item['price'] / $item['normal']) * 100) : 0;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function weightText(array $item): string
    {
        $c = trans('store.pages.catalog');

        return ($item['net'] ? "{$c['net']} {$item['net']}" : "{$c['pack']} {$item['pack']}").' g';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function shelfPlain(array $item): string
    {
        if (! $item['shelf']) {
            return '';
        }
        [$amount, $unit] = $item['shelf'];

        return $amount.' '.trans('store.pages.catalog.units')[$unit][$amount > 1 ? 1 : 0];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function shelfText(array $item): string
    {
        return $item['shelf'] ? trans('store.pages.catalog.lasts').' '.$this->shelfPlain($item) : '';
    }

    /**
     * @param  array<string, string|int>  $values
     */
    public function fill(string $text, array $values): string
    {
        foreach ($values as $key => $value) {
            $text = str_replace('{'.$key.'}', (string) $value, $text);
        }

        return $text;
    }

    /**
     * Teks dengan penanda: *miring* dan baris baru. Setiap potongan di-escape; hanya em dan br yang dibuat di sini.
     */
    public function rich(string $text): HtmlString
    {
        $html = '';

        foreach (explode('*', $text) as $i => $segment) {
            foreach (explode("\n", $segment) as $li => $line) {
                if ($li > 0) {
                    $html .= ' <br class="tf-br">';
                }
                if ($line !== '') {
                    $html .= $i % 2 ? '<em>'.e($line).'</em>' : e($line);
                }
            }
        }

        return new HtmlString($html);
    }

    /**
     * Material Symbols, sama dengan ikon di desain FigCraft 01 Landing. Nama ikon hanya dari kode, bukan dari pengguna.
     */
    public function icon(string $name, int $size): HtmlString
    {
        if (in_array($name, config('store_icons.masked'), true)) {
            return new HtmlString('<span class="tf-icon tf-icon-'.e($name).'" style="width: '.$size.'px; height: '.$size.'px;" aria-hidden="true"></span>');
        }

        return new HtmlString('<svg class="tf-icon" viewBox="0 -960 960 960" width="'.$size.'" height="'.$size.'" aria-hidden="true" fill="currentColor"><path d="'.e(config("store_icons.paths.{$name}")).'"></path></svg>');
    }

    /**
     * Data untuk tag script JSON. Tanda kurung sudut dan kutip di dalam nilai di-escape, jadi isi tidak bisa menutup tag script.
     */
    public function json(mixed $value): HtmlString
    {
        return new HtmlString((string) json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function lineIcon(string $name, int $size = 18): HtmlString
    {
        $icon = config("store_icons.line.{$name}");
        $attrs = $icon['line']
            ? 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
            : 'fill="currentColor"';

        return new HtmlString('<svg viewBox="0 0 24 24" width="'.$size.'" height="'.$size.'" aria-hidden="true" '.$attrs.'>'.$icon['inner'].'</svg>');
    }
}
