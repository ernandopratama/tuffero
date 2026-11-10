<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PromoContentRequest;
use App\Models\PromoContent;
use App\Services\PromoService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\ImageService;

class PromoContentController extends Controller
{
    use ApiResponse;

    public function __construct(private PromoService $promo) {}

    public function index(Request $request): JsonResponse
    {
        // scope=video: hanya video (halaman Video Website); scope=promo: banner dan teks; tanpa scope: semua.
        $page = PromoContent::query()
            ->when($request->query('scope') === 'video', fn ($q) => $q->where('type', 'video'))
            ->when($request->query('scope') === 'promo', fn ($q) => $q->where('type', '!=', 'video'))
            ->when($request->string('search')->trim()->toString(), fn ($q, $search) => $q->where('title', 'like', "%{$search}%"))
            ->when(in_array($request->query('type'), ['banner', 'text', 'video'], true), fn ($q) => $q->where('type', $request->query('type')))
            ->when($request->query('is_published') !== null && $request->query('is_published') !== '', fn ($q) => $q->where('is_published', $request->boolean('is_published')))
            ->orderByDesc('id')->paginate($this->perPage($request));

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

    public function refreshThumbnail(Request $request, PromoContent $content): JsonResponse
    {
        if ($this->promo->refreshThumbnail($content, $request->user()) === 'failed') {
            return $this->error('Thumbnail tidak berhasil diambil. Unggah manual.', [], 422);
        }

        return $this->success('Thumbnail fetched.', $this->present($content->refresh()));
    }

    public function destroy(Request $request, PromoContent $content): JsonResponse
    {
        $this->promo->deleteContent($content, $request->user());

        return $this->success('Content deleted.');
    }

    public function storeImage(Request $request, PromoContent $content): JsonResponse
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

        return $this->success('Image saved.', $this->present($this->promo->setContentImage($content, $validated['image'], $request->user())));
    }

    /** @return array<string, mixed> */
    private function present(PromoContent $content): array
    {
        return [
            ...$content->only(['id', 'type', 'title', 'body', 'video_url', 'provider', 'sort_order', 'starts_at', 'ends_at', 'is_published']),
            'image_url' => $content->image_path ? '/storage/'.$content->image_path : null,
            'can_auto_thumbnail' => $content->provider === 'youtube' && (bool) $content->external_id,
            'thumbnail_status' => $content->thumbnailStatus,
        ];
    }
}
