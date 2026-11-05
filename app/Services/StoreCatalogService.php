<?php

namespace App\Services;

use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductImage;
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
            ->with(['variants' => fn ($query) => $query->where('is_active', true)->orderBy('id'), 'images'])
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
        $products = $this->publishedProducts();
        $available = InventoryStock::availableByVariant($products->flatMap->variants->pluck('id'));

        return $this->items ??= $products
            ->flatMap(fn (Product $product) => $product->variants->map(fn (ProductVariant $variant) => $this->storeItem($product, $variant, ($available[$variant->id] ?? 0) > 0)))
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
    private function storeItem(Product $product, ProductVariant $variant, bool $inStock): array
    {
        // Kategori, jenis, dan nama Inggris belum ada di database (OQ-16); SKU baru tampil tanpa kategori dan memakai nama Indonesia.
        $meta = config("store_catalog.meta.{$variant->sku}", []);
        $name = $product->variants->count() > 1 ? "{$product->name} {$variant->name}" : $product->name;

        return [
            'sku' => $variant->sku,
            'path' => '/produk/'.strtolower($variant->sku),
            'cat' => $meta['cat'] ?? null,
            'type' => $meta['type'] ?? null,
            'name' => ['id' => $name, 'en' => $meta['en'] ?? $name],
            'description' => $product->description,
            'badges' => $product->badges ?? [],
            'in_stock' => $inStock,
            'max_quantity' => $variant->max_order_quantity,
            'price' => $variant->price,
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
