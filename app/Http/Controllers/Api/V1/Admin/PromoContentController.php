<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PromoContentRequest;
use App\Models\PromoContent;
use App\Services\PromoService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromoContentController extends Controller
{
    use ApiResponse;

    public function __construct(private PromoService $promo) {}

    public function index(): JsonResponse
    {
        $page = PromoContent::orderByDesc('id')->paginate(25);

        return $this->success('Contents retrieved.', [
            'items' => $page->getCollection()->map($this->present(...))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(PromoContentRequest $request): JsonResponse
    {
        return $this->success('Content created.', $this->present($this->promo->createContent($request->validated(), $request->user())), 201);
    }

    public function update(PromoContentRequest $request, PromoContent $content): JsonResponse
    {
        return $this->success('Content updated.', $this->present($this->promo->updateContent($content, $request->validated(), $request->user())));
    }

    public function storeImage(Request $request, PromoContent $content): JsonResponse
    {
        $validated = $request->validate([
            // Tanpa SVG: berkas itu bisa memuat skrip.
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=5000,max_height=5000'],
        ], [
            'image.required' => 'Pilih gambar dulu.',
            'image.mimes' => 'Gambar harus JPG, PNG, atau WebP.',
            'image.max' => 'Ukuran gambar maksimal 2 MB.',
            'image.dimensions' => 'Dimensi gambar maksimal 5000 × 5000 piksel.',
        ]);

        return $this->success('Image saved.', $this->present($this->promo->setContentImage($content, $validated['image'], $request->user())));
    }

    /** @return array<string, mixed> */
    private function present(PromoContent $content): array
    {
        return [
            ...$content->only(['id', 'type', 'title', 'body', 'video_url', 'starts_at', 'ends_at', 'is_published']),
            'image_url' => $content->image_path ? '/storage/'.$content->image_path : null,
        ];
    }
}
