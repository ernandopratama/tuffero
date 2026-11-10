<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\User;
use App\Services\UserManagementService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Akun pelanggan (tanpa role) untuk Super Admin, Pemilik, Admin Toko. Staf tidak bisa mengubah email atau WhatsApp pelanggan, tidak ada ekspor.
 */
class CustomerController extends Controller
{
    use ApiResponse;

    public function __construct(private UserManagementService $users) {}

    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->toString();
        $active = $request->input('is_active');

        $page = User::whereDoesntHave('roles')
            ->withCount('orders')
            ->when(in_array($active, ['0', '1'], true), fn ($query) => $query->where('is_active', $active === '1'))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->latest('id')
            ->paginate($this->perPage($request));

        return $this->success('Customers retrieved.', [
            'items' => $page->getCollection()->map($this->present(...))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $customer = $this->customer($user);
        $customer->loadCount('orders');
        // Detail memuat alamat dan nomor pribadi, jadi setiap pembukaan dicatat.
        AuditLog::record('customer.viewed', $customer, [], $request->user()->id);

        return $this->success('Customer retrieved.', [
            ...$this->present($customer),
            'addresses' => $customer->addresses()->orderByDesc('is_default')->oldest('id')->get()
                ->map(fn (CustomerAddress $address) => $address->only(['label', 'recipient_name', 'phone', 'street', 'district', 'city', 'province', 'postal_code', 'is_default'])),
            'orders' => $customer->orders()->latest('id')->limit(10)->get()
                ->map(fn (Order $order) => [
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'total' => $order->total,
                    'created_at' => $order->created_at,
                ]),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $customer = $this->customer($user);
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        // Lewat layanan yang sama dengan akun staf: tercatat di audit log dan session berakhir saat dinonaktifkan.
        $this->users->update($customer, ['is_active' => $request->boolean('is_active')], $request->user());

        return $this->success('Customer updated.', $this->present($customer->fresh()->loadCount('orders')));
    }

    private function customer(User $user): User
    {
        abort_if($user->getRoleNames()->isNotEmpty(), 404);

        return $user;
    }

    /** @return array<string, mixed> */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'phone' => $user->phone,
            'phone_verified' => $user->phone_verified_at !== null,
            'is_active' => $user->is_active,
            'orders_count' => $user->orders_count,
            'created_at' => $user->created_at,
        ];
    }
}
