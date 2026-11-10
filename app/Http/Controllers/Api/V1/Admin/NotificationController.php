<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Notifikasi milik staf yang sedang masuk. Semua kueri lewat relasi `$user->notifications()`, jadi tidak ada akses
 * ke notifikasi orang lain.
 */
class NotificationController extends Controller
{
    use ApiResponse;

    /** Lonceng menampilkan 5 per halaman. */
    private const PER_PAGE = 5;

    public function index(Request $request): JsonResponse
    {
        $user = $this->staff($request);
        $page = $user->notifications()->paginate(self::PER_PAGE);

        return $this->success('Notifications retrieved.', [
            'unread_count' => $user->unreadNotifications()->count(),
            // Waktu notifikasi belum dibaca terbaru: frontend membandingkannya untuk tahu ada yang baru, di halaman mana pun.
            'latest_unread_at' => $user->unreadNotifications()->max('created_at'),
            'items' => collect($page->items())->map(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                'read_at' => $n->read_at,
                'created_at' => $n->created_at,
                ...$n->data,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $this->staff($request)->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return $this->success('Notification read.');
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->staff($request)->unreadNotifications()->update(['read_at' => now()]);

        return $this->success('Notifications read.');
    }

    private function staff(Request $request): User
    {
        abort_unless($request->user()->getRoleNames()->isNotEmpty(), 403);

        return $request->user();
    }
}
