<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminComment;
use App\Models\Order;
use App\Models\PromoContent;
use App\Models\Voucher;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catatan internal pada data yang bisa diakses (D-045). Izin baca target dijaga di route. Sengaja tanpa edit dan hapus.
 */
class CommentController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        return $this->success('Comments retrieved.', [
            'comments' => $this->target($request)->comments()->with('user:id,name')->orderByDesc('id')->limit(100)->get()->map($this->present(...)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']], [
            'body.required' => 'Komentar tidak boleh kosong.',
            'body.max' => 'Komentar maksimal 2000 karakter.',
        ]);

        $comment = $this->target($request)->comments()->create(['user_id' => $request->user()->id, 'body' => $validated['body']]);

        return $this->success('Comment added.', $this->present($comment->load('user:id,name')), 201);
    }

    private function target(Request $request): Voucher|PromoContent|Order
    {
        return $request->route('voucher') ?? $request->route('content') ?? $request->route('order');
    }

    /** @return array<string, mixed> */
    private function present(AdminComment $comment): array
    {
        return ['id' => $comment->id, 'author' => $comment->user->name, 'body' => $comment->body, 'created_at' => $comment->created_at];
    }
}
