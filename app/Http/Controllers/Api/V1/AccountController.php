<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ChangeAccountPasswordRequest;
use App\Http\Requests\Account\UpdateAccountRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\ProfileService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Akun pelanggan yang sedang masuk (D-051). Staf memakai /admin/profile. Email dan WhatsApp tidak bisa diubah di sini sampai verifikasi ulang tersedia.
 */
class AccountController extends Controller
{
    use ApiResponse;

    public function __construct(private ProfileService $profiles) {}

    public function show(Request $request): JsonResponse
    {
        abort_if($request->user()->getRoleNames()->isNotEmpty(), 403);

        return $this->success('Account retrieved.', $this->present($request->user()));
    }

    public function update(UpdateAccountRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->profiles->update($user, ['name' => $request->validated('name'), 'email' => $user->email]);

        return $this->success('Account updated.', $this->present($user));
    }

    public function password(ChangeAccountPasswordRequest $request): JsonResponse
    {
        $this->profiles->changePassword($request->user(), $request->validated('password'), $request->session()->getId());

        return $this->success('Password changed.');
    }

    public function orders(Request $request): JsonResponse
    {
        abort_if($request->user()->getRoleNames()->isNotEmpty(), 403);

        $page = $request->user()->orders()->withSum('items', 'quantity')->latest('id')->paginate(10);

        return $this->success('Orders retrieved.', [
            'items' => $page->getCollection()->map(fn (Order $order) => [
                'public_ref' => $order->public_ref,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'total' => $order->total,
                'item_count' => (int) $order->getAttribute('items_sum_quantity'),
                'created_at' => $order->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'phone' => $user->phone,
            'phone_verified' => $user->phone_verified_at !== null,
        ];
    }
}
