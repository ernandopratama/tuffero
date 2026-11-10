<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\StoreReview;
use App\Services\DiscountService;
use App\Services\StoreCatalogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Katalog publik toko: hanya produk tayang dan varian aktif. Jumlah stok dan data internal tidak dikirim; hanya status ada atau tidak.
 */
class CatalogController extends Controller
{
    use ApiResponse;

    /** Ulasan toko yang tayang, dikelola Admin Toko (OQ-23); rating dan jumlah dari settings. */
    public function reviews(): JsonResponse
    {
        $settings = Setting::whereIn('key', ['reviews.rating', 'reviews.count'])->pluck('value', 'key');

        return $this->success('Reviews retrieved.', [
            'rating' => (float) ($settings['reviews.rating'] ?? 0),
            'count' => (int) ($settings['reviews.count'] ?? 0),
            'reviews' => StoreReview::where('is_published', true)->orderByDesc('id')->limit(20)->get(['author_name', 'rating', 'body', 'source', 'reviewed_on']),
        ]);
    }

    public function index(StoreCatalogService $catalog, DiscountService $discounts): JsonResponse
    {
        $products = $catalog->publishedProducts();
        $prices = $discounts->prices($products->flatMap->variants, $discounts->memberId());
        $available = InventoryStock::availableByVariant($products->flatMap->variants->pluck('id'));

        return $this->success('Catalog retrieved.', [
            'products' => $products->map(fn (Product $product) => [
                'name' => $product->name,
                'description' => $product->description,
                'badges' => $product->badges ?? [],
                'images' => $product->images->map(fn (ProductImage $image) => ['url' => '/storage/'.$image->path, 'alt' => $image->alt]),
                'variants' => $product->variants->map(fn (ProductVariant $variant) => [
                    ...$variant->only(['sku', 'name', 'compare_at_price', 'weight_gram', 'net_weight_gram', 'shelf_life_days']),
                    // Harga bayar setelah diskon produk. API ini tanpa session, jadi hanya diskon untuk semua dan Non Member.
                    'price' => $prices[$variant->id],
                    'list_price' => $variant->price,
                    'max_per_order' => $variant->max_order_quantity,
                    // Hanya ada atau tidak; jumlah stok tidak dikirim.
                    'in_stock' => ($available[$variant->id] ?? 0) > 0,
                ]),
            ]),
        ]);
    }
}
