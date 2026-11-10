<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CampaignItemRequest;
use App\Http\Requests\Admin\CampaignRequest;
use App\Models\Campaign;
use App\Models\ProductVariant;
use App\Models\VariantDiscount;
use App\Services\CampaignService;
use App\Services\ImageService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    use ApiResponse;

    public function __construct(private CampaignService $campaigns) {}

    public function index(): JsonResponse
    {
        return $this->success('Campaigns retrieved.', [
            'items' => Campaign::withCount('discounts')->orderByDesc('starts_at')->get()->map(fn (Campaign $campaign) => $this->present($campaign))->values(),
        ]);
    }

    public function show(Campaign $campaign): JsonResponse
    {
        return $this->success('Campaign retrieved.', $this->detail($campaign));
    }

    public function store(CampaignRequest $request): JsonResponse
    {
        return $this->success('Campaign created.', $this->detail($this->campaigns->create($request->validated(), $request->user())), 201);
    }

    public function update(CampaignRequest $request, Campaign $campaign): JsonResponse
    {
        return $this->success('Campaign updated.', $this->detail($this->campaigns->update($campaign, $request->validated(), $request->user())));
    }

    public function image(Request $request, Campaign $campaign): JsonResponse
    {
        $validated = $request->validate([
            // Tanpa SVG: berkas itu bisa memuat skrip.
            'image' => ['required', 'file', ImageService::MIMES, 'max:2048', 'dimensions:max_width=5000,max_height=5000'],
        ], [
            'image.required' => 'Pilih gambar dulu.',
            'image.mimes' => ImageService::MIME_MESSAGE,
            'image.max' => 'Ukuran gambar maksimal 2 MB.',
            'image.dimensions' => 'Dimensi gambar maksimal 5000 × 5000 piksel.',
        ]);

        return $this->success('Image saved.', $this->detail($this->campaigns->setImage($campaign, $validated['image'], $request->user())));
    }

    public function storeItem(CampaignItemRequest $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validated();
        $variant = ProductVariant::findOrFail($data['variant_id']);
        unset($data['variant_id']);

        $this->campaigns->addItem($campaign, $variant, $data, $request->user());

        return $this->success('Item added.', $this->detail($campaign), 201);
    }

    public function updateItem(CampaignItemRequest $request, VariantDiscount $discount): JsonResponse
    {
        abort_if($discount->campaign_id === null, 404);

        $this->campaigns->updateItem($discount, $request->validated(), $request->user());

        return $this->success('Item updated.', $this->detail($discount->campaign));
    }

    /** Pencarian varian untuk memilih produk kampanye (SKU atau nama). */
    public function variants(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->toString();

        $items = ProductVariant::with('product')->whereHas('product')
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('sku', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhereHas('product', fn ($product) => $product->where('name', 'like', "%{$search}%"))))
            ->orderBy('sku')->limit(20)->get();

        return $this->success('Variants retrieved.', ['items' => $items->map(fn (ProductVariant $variant) => [
            'id' => $variant->id, 'sku' => $variant->sku, 'label' => $this->label($variant), 'price' => $variant->price,
        ])->values()]);
    }

    /** @return array<string, mixed> */
    private function detail(Campaign $campaign): array
    {
        $campaign->load('discounts.variant.product');

        return [
            ...$this->present($campaign),
            'items' => $campaign->discounts->sortBy('id')->values()->map(fn (VariantDiscount $item) => [
                'id' => $item->id,
                'variant_id' => $item->product_variant_id,
                'sku' => $item->variant->sku,
                'label' => $this->label($item->variant),
                'price' => $item->variant->price,
                ...$item->only(['audience', 'type', 'value', 'is_active']),
            ]),
        ];
    }

    private function label(ProductVariant $variant): string
    {
        return $variant->product->name.($variant->name === 'Standar' ? '' : ' '.$variant->name);
    }

    /** @return array<string, mixed> */
    private function present(Campaign $campaign): array
    {
        $now = now();
        $status = match (true) {
            ! $campaign->is_active => 'draft',
            $campaign->ends_at->lt($now) => 'ended',
            $campaign->starts_at->gt($now) => 'upcoming',
            default => 'live',
        };

        return [
            ...$campaign->only(['id', 'slug', 'title', 'description', 'starts_at', 'ends_at', 'is_flash', 'is_active']),
            'status' => $status,
            'image_url' => $campaign->image_path ? '/storage/'.$campaign->image_path : null,
            'items_count' => $campaign->discounts_count ?? $campaign->discounts()->count(),
        ];
    }
}
