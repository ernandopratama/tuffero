<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductType;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\StoreReview;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Katalog publik toko: hanya produk tayang dan varian aktif. Stok dan data internal tidak dikirim.
 * Dipakai API /api/v1/catalog dan halaman Blade, supaya aturan tayang hanya ada di satu tempat.
 */
class StoreCatalogService
{
    public function __construct(private DiscountService $discounts) {}

    /** @var Collection<int, array<string, mixed>>|null */
    private ?Collection $items = null;

    /**
     * @return EloquentCollection<int, Product>
     */
    public function publishedProducts(): EloquentCollection
    {
        // shortcut: semua produk dikirim sekaligus karena toko menyaring di browser; beri halaman bila produk tayang melewati beberapa ratus.
        return Product::where('is_published', true)
            ->whereHas('variants', fn ($query) => $query->where('is_active', true))
            ->with(['variants' => fn ($query) => $query->where('is_active', true)->orderBy('id'), 'images', 'category', 'type'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Satu baris toko = satu SKU.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function items(): Collection
    {
        // Seluruh perhitungan di dalam ??=: sebelumnya query produk, stok, dan harga berjalan lagi di setiap panggilan walau hasilnya sudah tersimpan.
        return $this->items ??= (function () {
            $products = $this->publishedProducts();
            $available = InventoryStock::availableByVariant($products->flatMap->variants->pluck('id'));
            $prices = $this->discounts->prices($products->flatMap->variants, $this->discounts->memberId());

            return $products
                ->flatMap(fn (Product $product) => $product->variants->map(fn (ProductVariant $variant) => $this->storeItem($product, $variant, ($available[$variant->id] ?? 0) > 0, $prices[$variant->id])))
                ->values();
        })();
    }

    /**
     * Produk dalam kampanye (item toko), dengan harga yang berlaku bagi pelanggan ini. Untuk kampanye flash sale
     * ditambah jumlah terjual selama periode kampanye (pesanan yang belum batal atau kedaluwarsa).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function campaignItems(Campaign $campaign): Collection
    {
        $variantIds = $campaign->discounts()->where('is_active', true)->pluck('product_variant_id');
        $skus = ProductVariant::whereIn('id', $variantIds)->pluck('sku', 'id');

        $sold = $campaign->is_flash && $variantIds->isNotEmpty()
            ? OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereIn('order_items.product_variant_id', $variantIds)
                ->whereIn('orders.status', [Order::PENDING_PAYMENT, Order::PAID, Order::PROCESSING, Order::SHIPPED, Order::COMPLETED])
                ->whereBetween('orders.created_at', [$campaign->starts_at, $campaign->ends_at])
                ->groupBy('order_items.product_variant_id')
                ->selectRaw('order_items.product_variant_id as variant_id, sum(order_items.quantity) as sold')
                ->pluck('sold', 'variant_id')
            : collect();

        $soldBySku = $skus->mapWithKeys(fn (string $sku, int $id) => [strtolower($sku) => (int) ($sold[$id] ?? 0)]);

        $rows = [];
        foreach ($this->items() as $item) {
            $key = strtolower($item['sku']);
            if ($soldBySku->has($key)) {
                $rows[] = $this->withSold($item, $campaign->is_flash ? $soldBySku[$key] : null);
            }
        }

        return collect($rows);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function withSold(array $item, ?int $sold): array
    {
        $item['sold'] = $sold;

        return $item;
    }

    /**
     * Kategori aktif yang punya produk tayang, beserta jenis yang punya produk tayang, untuk filter katalog.
     * Kategori dan jenis dikelola dari dashboard (Super Admin dan Admin Toko).
     *
     * @return Collection<int, array{slug: string, name: array{id: string, en: string}, types: array<int, array{slug: string, name: array{id: string, en: string}}>}>
     */
    public function categories(): Collection
    {
        $items = $this->items();
        $usedCategories = $items->pluck('cat')->filter()->unique();
        $usedTypes = $items->pluck('type')->filter()->unique();

        return ProductCategory::where('is_active', true)
            ->with(['types' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->filter(fn (ProductCategory $category) => $usedCategories->contains($category->slug))
            ->map(fn (ProductCategory $category) => [
                'slug' => $category->slug,
                'name' => ['id' => $category->name_id, 'en' => $category->name_en],
                'types' => $category->types->filter(fn (ProductType $type) => $usedTypes->contains($type->slug))->map(fn (ProductType $type) => [
                    'slug' => $type->slug,
                    'name' => ['id' => $type->name_id, 'en' => $type->name_en],
                ])->values()->all(),
            ])
            ->values();
    }

    /**
     * Ulasan toko yang tayang, dikelola Admin Toko (D-055). Bila belum ada, dipakai data Google Maps yang diambil 2026-11-03.
     *
     * @return array{rating: float, count: int, reviews: array<int, array<string, mixed>>}
     */
    public function reviews(): array
    {
        $settings = Setting::whereIn('key', ['reviews.rating', 'reviews.count'])->pluck('value', 'key');
        $reviews = StoreReview::where('is_published', true)->orderByDesc('id')->limit(20)->get(['author_name', 'body']);

        return [
            'rating' => (float) ($settings['reviews.rating'] ?? 0) ?: (float) config('store_catalog.google_rating'),
            'count' => (int) ($settings['reviews.count'] ?? 0) ?: (int) config('store_catalog.google_review_count'),
            'reviews' => $reviews->isNotEmpty()
                ? $reviews->toArray()
                : array_map(fn (array $r) => ['author_name' => $r['name'], 'body' => $r['text']], config('store_catalog.google_reviews')),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $sku): ?array
    {
        return $this->items()->first(fn (array $item) => strtolower($item['sku']) === strtolower($sku));
    }

    /**
     * @return array<string, mixed>
     */
    private function storeItem(Product $product, ProductVariant $variant, bool $inStock, int $price): array
    {
        $category = $product->category?->is_active ? $product->category : null;
        $type = $category && $product->type?->is_active ? $product->type : null;
        // Nama Inggris dari database (D-065); kosong berarti jatuh kembali ke nama Indonesia.
        $multi = $product->variants->count() > 1;
        $name = $multi ? "{$product->name} {$variant->name}" : $product->name;
        $nameEn = $product->name_en ?: $product->name;
        if ($multi) {
            $nameEn .= ' '.($variant->name_en ?: $variant->name);
        }

        return [
            'sku' => $variant->sku,
            'path' => '/produk/'.strtolower($variant->sku),
            'cat' => $category?->slug,
            'type' => $type?->slug,
            'cat_name' => $category ? ['id' => $category->name_id, 'en' => $category->name_en] : null,
            'type_name' => $type ? ['id' => $type->name_id, 'en' => $type->name_en] : null,
            'name' => ['id' => $name, 'en' => $nameEn],
            'description' => $product->description,
            'description_en' => $product->description_en,
            'badges' => $product->badges ?? [],
            'in_stock' => $inStock,
            'max_quantity' => $variant->max_order_quantity,
            // price = harga bayar (setelah diskon produk untuk pelanggan ini); normal = harga coret.
            'price' => $price,
            'normal' => $variant->compare_at_price ?: $variant->price,
            'pack' => $variant->weight_gram,
            'net' => $variant->net_weight_gram,
            'shelf' => $this->shelf($variant->shelf_life_days),
            'photos' => $product->images->map(fn (ProductImage $image) => [
                'src' => $this->encodePath('/storage/'.$image->path),
                'alt' => $image->alt,
            ])->values()->all(),
        ];
    }

    // 30 hari tampil sebagai 1 bulan, 365 hari sebagai 1 tahun, sesuai satuan data produk.
    /**
     * @return array{int, string}|null
     */
    private function shelf(?int $days): ?array
    {
        if (! $days) {
            return null;
        }
        if ($days % 365 === 0) {
            return [$days / 365, 'year'];
        }
        if ($days % 30 === 0) {
            return [$days / 30, 'month'];
        }

        return [$days, 'day'];
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
