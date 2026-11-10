<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GalleryPhotoRequest;
use App\Models\GalleryPhoto;
use App\Services\GalleryService;
use App\Services\ImageService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GalleryPhotoController extends Controller
{
    use ApiResponse;

    public function __construct(private GalleryService $gallery) {}

    public function index(Request $request): JsonResponse
    {
        $page = GalleryPhoto::query()
            ->when($request->string('search')->trim()->toString(), fn ($q, $search) => $q->where(fn ($w) => $w->where('title', 'like', "%{$search}%")->orWhere('title_en', 'like', "%{$search}%")))
            ->when(array_key_exists((string) $request->query('category'), GalleryPhoto::CATEGORIES), fn ($q) => $q->where('category', $request->query('category')))
            ->when($request->query('is_published') !== null && $request->query('is_published') !== '', fn ($q) => $q->where('is_published', $request->boolean('is_published')))
            ->orderBy('sort_order')->orderBy('id')->paginate($this->perPage($request));

        return $this->success('Gallery photos retrieved.', [
            'items' => $page->getCollection()->map($this->present(...))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(GalleryPhotoRequest $request): JsonResponse
    {
        $data = $request->safe()->except('image');

        return $this->success('Gallery photo created.', $this->present($this->gallery->create($data, $request->file('image'), $request->user())), 201);
    }

    public function update(GalleryPhotoRequest $request, GalleryPhoto $photo): JsonResponse
    {
        return $this->success('Gallery photo updated.', $this->present($this->gallery->update($photo, $request->safe()->except('image'), $request->user())));
    }

    public function storeImage(Request $request, GalleryPhoto $photo): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'file', ImageService::MIMES, 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
        ], [
            'image.required' => 'Pilih foto dulu.',
            'image.mimes' => ImageService::MIME_MESSAGE,
            'image.max' => 'Ukuran foto maksimal 5 MB.',
            'image.dimensions' => 'Dimensi foto maksimal 6000 × 6000 piksel.',
        ]);

        return $this->success('Image saved.', $this->present($this->gallery->setImage($photo, $validated['image'], $request->user())));
    }

    public function destroy(Request $request, GalleryPhoto $photo): JsonResponse
    {
        $this->gallery->delete($photo, $request->user());

        return $this->success('Gallery photo deleted.');
    }

    /** @return array<string, mixed> */
    private function present(GalleryPhoto $photo): array
    {
        return [
            ...$photo->only(['id', 'title', 'title_en', 'category', 'is_featured', 'sort_order', 'is_published']),
            'image_url' => $photo->imageUrl(),
        ];
    }
}
