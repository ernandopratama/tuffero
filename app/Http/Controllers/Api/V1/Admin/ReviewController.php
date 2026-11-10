<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewRequest;
use App\Models\AuditLog;
use App\Models\StoreReview;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReviewController extends Controller
{
    use ApiResponse;

    private const FIELDS = ['id', 'author_name', 'rating', 'body', 'source', 'reviewed_on', 'is_published'];

    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->toString();

        $page = StoreReview::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('author_name', 'like', "%{$search}%")
                ->orWhere('body', 'like', "%{$search}%")))
            ->when(in_array($request->query('rating'), ['1', '2', '3', '4', '5'], true), fn ($query) => $query->where('rating', (int) $request->query('rating')))
            ->when($request->query('is_published') !== null && $request->query('is_published') !== '', fn ($query) => $query->where('is_published', $request->boolean('is_published')))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->success('Reviews retrieved.', [
            'items' => $page->getCollection()->map(fn (StoreReview $review) => $review->only(self::FIELDS))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(ReviewRequest $request): JsonResponse
    {
        $review = DB::transaction(function () use ($request) {
            $review = StoreReview::create($request->validated())->refresh();
            AuditLog::record('review.created', $review, ['author' => $review->author_name, 'rating' => $review->rating], $request->user()->id);

            return $review;
        });

        return $this->success('Review created.', $review->only(self::FIELDS), 201);
    }

    public function update(ReviewRequest $request, StoreReview $review): JsonResponse
    {
        DB::transaction(fn () => AuditLog::saveWithAudit($review, $request->validated(), 'review.updated', $request->user()));

        return $this->success('Review updated.', $review->refresh()->only(self::FIELDS));
    }

    public function destroy(Request $request, StoreReview $review): JsonResponse
    {
        DB::transaction(function () use ($request, $review) {
            $review->delete();
            AuditLog::record('review.deleted', $review, ['author' => $review->author_name], $request->user()->id);
        });

        return $this->success('Review deleted.');
    }
}
