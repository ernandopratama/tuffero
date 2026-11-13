<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminComment;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\PromoContent;
use App\Models\Refund;
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
        // Catatan `cs` disaring di server: pengguna tanpa izin `comments.cs` tidak pernah menerimanya.
        $query = $this->target($request)->comments()->with('user:id,name')->orderByDesc('id');
        if (! $this->canWriteCs($request)) {
            $query->where('visibility', AdminComment::STAFF);
        }
        $page = $query->paginate(self::PER_PAGE);
        $comments = collect($page->items());
        $names = User::whereIn('id', $comments->pluck('mentions')->flatten()->filter()->unique())->pluck('name', 'id');
        $csIds = $this->canWriteCs($request) ? $this->mentionable($request, true)->pluck('id') : collect();

        return $this->success('Comments retrieved.', [
            'comments' => $comments->map(fn (AdminComment $comment) => $this->present($comment, $names)),
            'mentionable' => $this->mentionable($request)->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'cs' => $csIds->contains($user->id)])->values(),
            'can_cs' => $this->canWriteCs($request),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $csOnly = $request->input('visibility') === AdminComment::CS;
        abort_if($csOnly && ! $this->canWriteCs($request), 403, 'Forbidden.');

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'visibility' => ['sometimes', Rule::in([AdminComment::STAFF, AdminComment::CS])],
            'mentions' => ['sometimes', 'array', 'max:10'],
            'mentions.*' => ['integer', Rule::in($this->mentionable($request, $csOnly)->pluck('id'))],
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
            'visibility' => $csOnly ? AdminComment::CS : AdminComment::STAFF,
            'mentions' => $mentions->all() ?: null,
        ]);

        // Hanya jenis dan target yang dicatat; isi catatan CS tidak ikut masuk audit log.
        if ($csOnly) {
            AuditLog::record('comment.cs_created', $this->target($request), ['comment_id' => $comment->id], $request->user()->id);
        }

        // Langsung terkirim (tanpa antrean) supaya lonceng penerima terisi pada polling berikutnya.
        if ($mentions->isNotEmpty()) {
            [$kind, $label] = $this->describe($this->target($request));
            Notification::sendNow(User::whereIn('id', $mentions)->get(), new MentionedInComment($request->user()->name, $kind, $label, $validated['body']));
        }

        return $this->success('Comment added.', $this->present($comment->load('user:id,name'), User::whereIn('id', $mentions)->pluck('name', 'id')), 201);
    }

    private function target(Request $request): Voucher|PromoContent|Order|Refund|OrderCancellationRequest
    {
        return $request->route('voucher') ?? $request->route('content') ?? $request->route('order') ?? $request->route('refund') ?? $request->route('cancellation');
    }

    private function canWriteCs(Request $request): bool
    {
        return $request->user()->can(AdminComment::CS_PERMISSION);
    }

    /**
     * Jenis dan label data untuk teks notifikasi; frontend memetakannya ke halaman daftar.
     *
     * @return array{string, string}
     */
    private function describe(Voucher|PromoContent|Order|Refund|OrderCancellationRequest $target): array
    {
        return match (true) {
            $target instanceof Order => ['order', $target->order_number],
            $target instanceof Refund => ['refund', $target->refund_number],
            $target instanceof OrderCancellationRequest => ['cancellation', $target->request_number],
            $target instanceof Voucher => ['voucher', $target->code],
            default => [$target->type === 'video' ? 'video' : 'content', $target->title],
        };
    }

    /**
     * Staf aktif yang boleh membuka data ini (izin sama dengan route), selain pembuat catatan. Orang yang tidak bisa
     * melihat datanya tidak bisa ditandai. Untuk catatan `cs`, hanya pemegang izin `comments.cs`.
     *
     * @return Collection<int, User>
     */
    private function mentionable(Request $request, bool $csOnly = false): Collection
    {
        $permissions = match (true) {
            $request->route('order') !== null => ['orders.view', 'orders.view_fulfillment'],
            $request->route('voucher') !== null => ['vouchers.view'],
            $request->route('refund') !== null => ['refunds.view'],
            $request->route('cancellation') !== null => ['orders.cancel_request', 'orders.cancel', 'orders.cancel_final'],
            default => ['content.view'],
        };

        $query = User::permission($permissions)->where('is_active', true)->whereKeyNot($request->user()->id)->orderBy('name');
        if ($csOnly) {
            $query->permission(AdminComment::CS_PERMISSION);
        }

        return $query->get(['id', 'name']);
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
            'visibility' => $comment->visibility,
            'mentions' => collect($comment->mentions ?? [])->filter(fn ($id) => $names->has($id))->map(fn ($id) => ['id' => $id, 'name' => $names[$id]])->values(),
            'created_at' => $comment->created_at,
        ];
    }
}
