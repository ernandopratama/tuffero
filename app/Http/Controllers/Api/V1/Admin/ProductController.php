<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Http\Requests\Admin\VariantRequest;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\DiscountService;
use App\Services\ImageService;
use App\Services\ProductService;
use App\Services\Translation\ProductTranslationService;
use App\Services\Translation\TranslationException;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponse;

    public function __construct(private ProductService $products, private ProductTranslationService $translations, private DiscountService $discounts) {}

    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->toString();

        $page = Product::with(['variants', 'images'])
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhereHas('variants', fn ($variant) => $variant->where('sku', 'like', "%{$search}%"))))
            ->when(in_array($request->query('status'), ['published', 'draft'], true), fn ($query) => $query->where('is_published', $request->query('status') === 'published'))
            ->when($request->integer('category_id'), fn ($query, $id) => $query->where('product_category_id', $id))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return $this->success('Products retrieved.', [
            'items' => $page->getCollection()->map($this->present(...))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Product $product): JsonResponse
    {
        return $this->success('Product retrieved.', $this->present($product));
    }

    public function store(ProductRequest $request): JsonResponse
    {
        return $this->success('Product created.', $this->present($this->products->createProduct($request->validated(), $request->user())), 201);
    }

    public function update(ProductRequest $request, Product $product): JsonResponse
    {
        return $this->success('Product updated.', $this->present($this->products->updateProduct($product, $request->validated(), $request->user())));
    }

    /** Usulan terjemahan Inggris dari AI. Tidak menyimpan apa pun: admin memeriksa lalu menyimpan sendiri. */
    public function translate(Product $product): JsonResponse
    {
        try {
            return $this->success('Translation suggested.', $this->translations->suggest($product));
        } catch (TranslationException $e) {
            report($e);

            return $this->error('Terjemahan AI belum bisa dipakai sekarang. Isi manual atau coba lagi nanti.', [], 503);
        }
    }

    public function storeVariant(VariantRequest $request, Product $product): JsonResponse
    {
        $this->products->createVariant($product, $request->validated(), $request->user());

        return $this->success('Variant created.', $this->present($product), 201);
    }

    public function updateVariant(VariantRequest $request, ProductVariant $variant): JsonResponse
    {
        $this->products->updateVariant($variant, $request->validated(), $request->user());

        return $this->success('Variant updated.', $this->present($variant->product));
    }

    public function storeImage(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            // Tanpa SVG: berkas itu bisa memuat skrip.
            'image' => ['required', 'file', ImageService::MIMES, 'max:2048', 'dimensions:max_width=5000,max_height=5000'],
            'alt' => ['required', 'string', 'max:150'],
        ], [
            'image.required' => 'Pilih foto dulu.',
            'image.mimes' => ImageService::MIME_MESSAGE,
            'image.max' => 'Ukuran foto maksimal 2 MB.',
            'image.dimensions' => 'Dimensi foto maksimal 5000 × 5000 piksel.',
            'alt.required' => 'Teks alternatif foto wajib diisi.',
        ]);

        $this->products->addImage($product, $validated['image'], $validated['alt'], $request->user());

        return $this->success('Image added.', $this->present($product), 201);
    }

    public function reorderImages(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:50'], 'ids.*' => ['integer', 'distinct']]);

        $this->products->reorderImages($product, array_map('intval', $validated['ids']), $request->user());

        return $this->success('Images reordered.', $this->present($product));
    }

    public function destroyImage(Request $request, ProductImage $image): JsonResponse
    {
        $product = $image->product;

        $this->products->removeImage($image, $request->user());

        return $this->success('Image removed.', $this->present($product));
    }

    /** @return array<string, mixed> */
    private function present(Product $product): array
    {
        $product->load(['variants.discounts', 'images']);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'product_category_id' => $product->product_category_id,
            'product_type_id' => $product->product_type_id,
            'description' => $product->description,
            'name_en' => $product->name_en,
            'description_en' => $product->description_en,
            'translation_mode' => $product->translation_mode,
            'translation_outdated' => $this->translations->isOutdated($product),
            'badges' => $product->badges ?? [],
            'is_published' => $product->is_published,
            'max_discount_percent' => $this->discounts->maxPercent(),
            'variants' => $product->variants->sortBy('id')->values()->map(fn (ProductVariant $variant) => $variant->only([
                'id', 'sku', 'name', 'name_en', 'price', 'compare_at_price', 'weight_gram', 'net_weight_gram',
                'length_cm', 'width_cm', 'height_cm', 'shelf_life_days', 'max_order_quantity', 'low_stock_threshold', 'is_active',
            ]) + ['discounts' => $variant->discounts->whereNull('campaign_id')->sortByDesc('id')->values()->map(fn ($discount) => $discount->only([
                'id', 'audience', 'type', 'value', 'starts_at', 'ends_at', 'is_active',
            ]))]),
            'images' => $product->images->map(fn (ProductImage $image) => [
                'id' => $image->id,
                'url' => '/storage/'.$image->path,
                'alt' => $image->alt,
            ]),
        ];
    }
}
