<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Services\MfaService;
use App\Services\UserManagementService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    use ApiResponse;

    public function __construct(private UserManagementService $users) {}

    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->toString();

        // Halaman ini khusus staf; pelanggan adalah akun tanpa role.
        $page = User::with('roles')
            ->whereHas('roles')
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($request->query('role'), fn ($query, $role) => $query->whereHas('roles', fn ($r) => $r->where('name', $role)))
            ->when($request->query('is_active') !== null && $request->query('is_active') !== '', fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return $this->success('Users retrieved.', [
            'items' => $page->getCollection()->map($this->present(...))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function roles(): JsonResponse
    {
        return $this->success('Roles retrieved.', [
            'roles' => Role::with('permissions')->orderBy('id')->get()->map(fn (Role $role) => [
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->sort()->values(),
            ]),
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        return $this->success('User created.', $this->present($this->users->create($request->validated(), $request->user())), 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        return $this->success('User updated.', $this->present($this->users->update($user, $request->validated(), $request->user())));
    }

    public function resetMfa(Request $request, User $user, MfaService $mfa): JsonResponse
    {
        if ($user->is($request->user())) {
            return $this->error('Anda tidak bisa mereset MFA akun sendiri.', [], 422);
        }

        $mfa->reset($user, $request->user()->id);

        return $this->success('MFA reset.', $this->present($user));
    }

    /** @return array<string, mixed> */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getRoleNames()->first(),
            'is_active' => $user->is_active,
            'mfa_required' => $user->mfa_required,
            'mfa_enrolled' => $user->mfa_totp_confirmed_at !== null,
            'created_at' => $user->created_at,
        ];
    }
}
