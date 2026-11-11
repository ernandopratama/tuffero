<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductReviewRequest;
use App\Models\AuditLog;
use App\Models\ProductReview;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ulasan produk dari pembeli. Semua peran bisa melihat (product_reviews.view); tambah, ubah, hapus hanya Super Admin (product_reviews.manage).
 */
class ProductReviewController extends Controller
{
    use ApiResponse;

    private const FIELDS = ['id', 'product_id', 'author_name', 'rating', 'body', 'is_published', 'created_at'];

    /** @return array<string, mixed> */
    private function present(ProductReview $review): array
    {
        return [...$review->only(self::FIELDS), 'product_name' => $review->product?->name, 'from_order' => $review->order_item_id !== null];
    }

    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->toString();

        $page = ProductReview::with('product:id,name')
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('author_name', 'like', "%{$search}%")
                ->orWhere('body', 'like', "%{$search}%")
                ->orWhereHas('product', fn ($product) => $product->where('name', 'like', "%{$search}%"))))
            ->when(in_array($request->query('rating'), ['1', '2', '3', '4', '5'], true), fn ($query) => $query->where('rating', (int) $request->query('rating')))
            ->when($request->query('is_published') !== null && $request->query('is_published') !== '', fn ($query) => $query->where('is_published', $request->boolean('is_published')))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->success('Product reviews retrieved.', [
            'items' => $page->getCollection()->map(fn (ProductReview $review) => $this->present($review))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(ProductReviewRequest $request): JsonResponse
    {
        $review = DB::transaction(function () use ($request) {
            $review = ProductReview::create($request->validated())->refresh();
            AuditLog::record('product_review.created', $review, ['product_id' => $review->product_id, 'rating' => $review->rating], $request->user()->id);

            return $review;
        });

        return $this->success('Product review created.', $this->present($review), 201);
    }

    public function update(ProductReviewRequest $request, ProductReview $productReview): JsonResponse
    {
        DB::transaction(fn () => AuditLog::saveWithAudit($productReview, $request->validated(), 'product_review.updated', $request->user()));

        return $this->success('Product review updated.', $this->present($productReview->refresh()));
    }

    public function destroy(Request $request, ProductReview $productReview): JsonResponse
    {
        DB::transaction(function () use ($request, $productReview) {
            $productReview->delete();
            AuditLog::record('product_review.deleted', $productReview, ['product_id' => $productReview->product_id], $request->user()->id);
        });

        return $this->success('Product review deleted.');
    }
}
