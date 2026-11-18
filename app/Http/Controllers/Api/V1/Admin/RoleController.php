<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\RoleService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Role dan izin (D-129). Semua rute memerlukan `roles.manage` (hanya Super Admin). Setiap perubahan wajib MFA terdaftar
 * dan konfirmasi ulang kata sandi; aturan keamanannya ada di RoleService.
 */
class RoleController extends Controller
{
    use ApiResponse;

    private const MESSAGES = [
        'password.required' => 'Masukkan kata sandi Anda untuk mengonfirmasi.',
        'password.current_password' => 'Kata sandi salah.',
        'name.required' => 'Kode wajib diisi.',
        'name.regex' => 'Kode hanya huruf kecil, angka, dan tanda hubung, diawali huruf.',
        'label.required' => 'Nama tampilan wajib diisi.',
    ];

    public function __construct(private RoleService $roles) {}

    public function index(): JsonResponse
    {
        return $this->success('Access retrieved.', $this->roles->overview());
    }

    public function storeRole(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->confirm(),
            'name' => ['required', 'string', 'regex:/^[a-z][a-z0-9-]{2,39}$/'],
            'label' => ['required', 'string', 'max:100'],
            'permissions' => ['present', 'array', 'max:200'],
            'permissions.*' => ['string', 'max:100'],
        ], self::MESSAGES);

        $role = $this->roles->createRole($data['name'], trim(strip_tags($data['label'])), $data['permissions'], $request->user());

        return $this->success('Role created.', ['id' => $role->getAttribute('public_id')], 201);
    }

    public function updateRole(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            ...$this->confirm(),
            'label' => ['sometimes', 'required', 'string', 'max:100'],
            'add' => ['present', 'array', 'max:200'],
            'add.*' => ['string', 'max:100'],
            'remove' => ['present', 'array', 'max:200'],
            'remove.*' => ['string', 'max:100'],
        ], self::MESSAGES);

        $label = isset($data['label']) ? trim(strip_tags($data['label'])) : null;
        $this->roles->updateRole($role, $label, $data['add'], $data['remove'], $request->user());

        return $this->success('Role updated.');
    }

    public function destroyRole(Request $request, Role $role): JsonResponse
    {
        $request->validate($this->confirm(), self::MESSAGES);
        $this->roles->deleteRole($role, $request->user());

        return $this->success('Role deleted.');
    }

    public function storePermission(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->confirm(),
            'name' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/'],
            'label' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [...self::MESSAGES, 'name.regex' => 'Kode izin berformat kelompok.aksi, huruf kecil, contoh laporan.export.']);

        $permission = $this->roles->createPermission($data['name'], trim(strip_tags($data['label'])), $this->text($data['description'] ?? null), $request->user());

        return $this->success('Permission created.', ['id' => $permission->getAttribute('public_id')], 201);
    }

    public function updatePermission(Request $request, Permission $permission): JsonResponse
    {
        $data = $request->validate([
            ...$this->confirm(),
            'label' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ], self::MESSAGES);

        $this->roles->updatePermission($permission, trim(strip_tags($data['label'])), $this->text($data['description'] ?? null), $request->user());

        return $this->success('Permission updated.');
    }

    public function destroyPermission(Request $request, Permission $permission): JsonResponse
    {
        $request->validate($this->confirm(), self::MESSAGES);
        $this->roles->deletePermission($permission, $request->user());

        return $this->success('Permission deleted.');
    }

    /** @return array<string, list<string>> */
    private function confirm(): array
    {
        return ['password' => ['required', 'string', 'current_password']];
    }

    private function text(?string $value): ?string
    {
        $value = trim(strip_tags((string) $value));

        return $value === '' ? null : $value;
    }
}
