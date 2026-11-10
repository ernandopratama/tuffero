<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminComment;
use App\Models\Order;
use App\Models\PromoContent;
use App\Models\User;
use App\Models\Voucher;
use App\Notifications\MentionedInComment;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/**
 * Catatan internal pada data yang bisa diakses (D-045). Izin baca target dijaga di route. Sengaja tanpa edit dan hapus.
 */
class CommentController extends Controller
{
    use ApiResponse;

    /** Catatan ditampilkan 5 per halaman. */
    private const PER_PAGE = 5;

    public function index(Request $request): JsonResponse
    {
        $page = $this->target($request)->comments()->with('user:id,name')->orderByDesc('id')->paginate(self::PER_PAGE);
        $comments = collect($page->items());
        $names = User::whereIn('id', $comments->pluck('mentions')->flatten()->filter()->unique())->pluck('name', 'id');

        return $this->success('Comments retrieved.', [
            'comments' => $comments->map(fn (AdminComment $comment) => $this->present($comment, $names)),
            'mentionable' => $this->mentionable($request)->map->only(['id', 'name'])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'mentions' => ['sometimes', 'array', 'max:10'],
            'mentions.*' => ['integer', Rule::in($this->mentionable($request)->pluck('id'))],
        ], [
            'body.required' => 'Komentar tidak boleh kosong.',
            'body.max' => 'Komentar maksimal 2000 karakter.',
            'mentions.max' => 'Maksimal 10 orang yang ditandai.',
            'mentions.*.in' => 'Orang yang ditandai tidak valid.',
        ]);

        $mentions = collect($validated['mentions'] ?? [])->unique()->values();
        $comment = $this->target($request)->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
            'mentions' => $mentions->all() ?: null,
        ]);

        // Langsung terkirim (tanpa antrean) supaya lonceng penerima terisi pada polling berikutnya.
        if ($mentions->isNotEmpty()) {
            [$kind, $label] = $this->describe($this->target($request));
            Notification::sendNow(User::whereIn('id', $mentions)->get(), new MentionedInComment($request->user()->name, $kind, $label, $validated['body']));
        }

        return $this->success('Comment added.', $this->present($comment->load('user:id,name'), User::whereIn('id', $mentions)->pluck('name', 'id')), 201);
    }

    private function target(Request $request): Voucher|PromoContent|Order
    {
        return $request->route('voucher') ?? $request->route('content') ?? $request->route('order');
    }

    /**
     * Jenis dan label data untuk teks notifikasi; frontend memetakannya ke halaman daftar.
     *
     * @return array{string, string}
     */
    private function describe(Voucher|PromoContent|Order $target): array
    {
        return match (true) {
            $target instanceof Order => ['order', $target->order_number],
            $target instanceof Voucher => ['voucher', $target->code],
            default => [$target->type === 'video' ? 'video' : 'content', $target->title],
        };
    }

    /**
     * Staf aktif yang boleh membuka data ini (izin sama dengan route), selain pembuat catatan. Orang yang tidak bisa
     * melihat datanya tidak bisa ditandai.
     *
     * @return Collection<int, User>
     */
    private function mentionable(Request $request): Collection
    {
        $permissions = match (true) {
            $request->route('order') !== null => ['orders.view', 'orders.view_fulfillment'],
            $request->route('voucher') !== null => ['vouchers.view'],
            default => ['content.view'],
        };

        return User::permission($permissions)->where('is_active', true)->whereKeyNot($request->user()->id)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @param  Collection<int|string, string>  $names  id staf => nama
     * @return array<string, mixed>
     */
    private function present(AdminComment $comment, Collection $names): array
    {
        return [
            'id' => $comment->id,
            'author' => $comment->user->name,
            'body' => $comment->body,
            'mentions' => collect($comment->mentions ?? [])->filter(fn ($id) => $names->has($id))->map(fn ($id) => ['id' => $id, 'name' => $names[$id]])->values(),
            'created_at' => $comment->created_at,
        ];
    }
}
