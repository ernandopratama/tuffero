<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NewsItemRequest;
use App\Models\NewsItem;
use App\Services\ImageService;
use App\Services\NewsService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Berita popup beranda (D-085). Lihat: semua role admin (`news.view`). Kelola: Marketing dan Super Admin (`news.manage`). */
class NewsItemController extends Controller
{
    use ApiResponse;

    public function __construct(private NewsService $news) {}

    public function index(): JsonResponse
    {
        return $this->success('News retrieved.', [
            'items' => $this->news->manualList()->map($this->present(...))->values(),
            // Slot popup yang terpakai sekarang (kampanye berjalan + berita tayang), dibatasi LIMIT.
            'popup' => ['limit' => NewsService::LIMIT, 'used' => $this->news->slotsUsed()],
        ]);
    }

    public function store(NewsItemRequest $request): JsonResponse
    {
        return $this->success('News created.', $this->present($this->news->create($request->validated(), $request->user())), 201);
    }

    public function update(NewsItemRequest $request, NewsItem $item): JsonResponse
    {
        return $this->success('News updated.', $this->present($this->news->update($item, $request->validated(), $request->user())));
    }

    public function storeImage(Request $request, NewsItem $item): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'file', ImageService::MIMES, 'max:3072', 'dimensions:max_width=5000,max_height=5000'],
        ], [
            'image.required' => 'Pilih gambar dulu.',
            'image.mimes' => ImageService::MIME_MESSAGE,
            'image.max' => 'Ukuran gambar maksimal 3 MB.',
            'image.dimensions' => 'Dimensi gambar maksimal 5000 × 5000 piksel.',
        ]);

        return $this->success('Image saved.', $this->present($this->news->setImage($item, $validated['image'], $request->user())));
    }

    public function destroyImage(Request $request, NewsItem $item): JsonResponse
    {
        return $this->success('Image removed.', $this->present($this->news->removeImage($item, $request->user())));
    }

    public function destroy(Request $request, NewsItem $item): JsonResponse
    {
        $this->news->delete($item, $request->user());

        return $this->success('News deleted.');
    }

    /** @return array<string, mixed> */
    private function present(NewsItem $item): array
    {
        $now = now();
        $status = match (true) {
            ! $item->is_published => 'draft',
            $item->ends_at && $item->ends_at->lt($now) => 'ended',
            $item->starts_at && $item->starts_at->gt($now) => 'upcoming',
            default => 'live',
        };

        return [
            ...$item->only(['id', 'kind', 'title', 'title_en', 'body', 'body_en', 'cta_label', 'cta_label_en', 'cta_url', 'starts_at', 'ends_at', 'sort_order', 'is_published']),
            'status' => $status,
            'image_url' => $item->imageUrl(),
        ];
    }
}
