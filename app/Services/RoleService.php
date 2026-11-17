<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\StaffEvent;
use App\Support\PermissionCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CRUD role dan izin oleh Super Admin (D-129). Aturan keamanan dijaga di sini, bukan hanya di tampilan:
 * - Role Super Admin tidak bisa diubah dan selalu memegang semua izin (izin kustom baru otomatis ikut).
 * - Izin khusus Super Admin (PermissionCatalog::SUPER_ADMIN_ONLY) tidak bisa diberikan ke role lain.
 * - Role bawaan tidak bisa dihapus atau diganti nama; role kustom hanya bisa dihapus bila tidak ada akun yang memakainya.
 * - Izin bawaan tidak bisa dihapus atau diganti nama; izin kustom boleh dibuat, diubah labelnya, dan dihapus.
 * Setiap perubahan dicatat di audit log dan diberitahukan ke Super Admin lain.
 */
class RoleService
{
    public function __construct(private StaffNotifier $notifier) {}

    /** @return array{roles: list<array<string, mixed>>, permissions: list<array<string, mixed>>, super_admin_only: list<string>} */
    public function overview(): array
    {
        $counts = DB::table(config('permission.table_names.model_has_roles'))
            ->where('model_type', (new User)->getMorphClass())
            ->selectRaw('role_id, count(*) as total')->groupBy('role_id')->pluck('total', 'role_id');

        $roles = Role::with('permissions:id,name')->orderBy('id')->get()->map(fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->name,
            'label' => self::roleLabel($role),
            'system' => PermissionCatalog::isSystemRole($role->name),
            'locked' => $role->name === PermissionCatalog::SUPER_ADMIN,
            'users_count' => (int) ($counts[$role->id] ?? 0),
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
        ])->values()->all();

        $permissions = Permission::orderBy('name')->get()->map(fn (Permission $permission) => [
            'id' => $permission->id,
            'name' => $permission->name,
            'group' => PermissionCatalog::PERMISSIONS[$permission->name][0] ?? PermissionCatalog::CUSTOM_GROUP,
            'label' => PermissionCatalog::PERMISSIONS[$permission->name][1] ?? (data_get($permission, 'label') ?: $permission->name),
            'description' => data_get($permission, 'description'),
            'system' => PermissionCatalog::isSystem($permission->name),
            'super_admin_only' => in_array($permission->name, PermissionCatalog::SUPER_ADMIN_ONLY, true),
        ])->values()->all();

        return ['roles' => $roles, 'permissions' => $permissions, 'super_admin_only' => PermissionCatalog::SUPER_ADMIN_ONLY];
    }

    public static function roleLabel(?Model $role): ?string
    {
        if (! $role) {
            return null;
        }

        $name = (string) data_get($role, 'name');

        return PermissionCatalog::SYSTEM_ROLES[$name] ?? (data_get($role, 'label') ?: $name);
    }

    /** @param  list<string>  $permissions */
    public function createRole(string $name, string $label, array $permissions, User $actor): Role
    {
        $permissions = $this->assignable($permissions);
        $this->assertLabel($label);

        return $this->changed($actor, 'role.created', function () use ($name, $label, $permissions, $actor) {
            if (Role::where('name', $name)->exists() || PermissionCatalog::isSystemRole($name)) {
                throw ValidationException::withMessages(['name' => 'Kode role sudah dipakai.']);
            }

            $role = (new Role)->forceFill(['name' => $name, 'guard_name' => 'web', 'label' => $label]);
            $role->save();
            $role->syncPermissions($permissions);
            AuditLog::record('role.created', $role, ['name' => $name, 'label' => $label, 'permissions' => $permissions], $actor->id);

            return [$role, "Role baru dibuat: {$label}"];
        });
    }

    /**
     * Hanya menerapkan selisih (ditambah dan dicabut), bukan daftar lengkap: dua Super Admin yang menyimpan role yang sama dari
     * halaman yang sudah lama dibuka tidak saling menimpa perubahan yang tidak mereka sentuh.
     *
     * @param  list<string>  $add
     * @param  list<string>  $remove
     */
    public function updateRole(Role $role, ?string $label, array $add, array $remove, User $actor): Role
    {
        if ($role->name === PermissionCatalog::SUPER_ADMIN) {
            throw ValidationException::withMessages(['role' => 'Role Super Admin tidak bisa diubah. Super Admin selalu memegang semua izin.']);
        }

        $add = $this->assignable($add);
        $this->assertLabel($label);

        return $this->changed($actor, 'role.updated', function () use ($role, $label, $add, $remove, $actor) {
            $locked = Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
            $before = $locked->permissions()->pluck('name')->all();
            $permissions = array_values(array_diff(array_unique([...$before, ...$add]), $remove));
            $changes = [
                'added' => array_values(array_diff($permissions, $before)),
                'removed' => array_values(array_diff($before, $permissions)),
            ];

            // Nama tampilan role bawaan tetap dari katalog.
            if ($label !== null && ! PermissionCatalog::isSystemRole($locked->name) && $label !== data_get($locked, 'label')) {
                $changes['label'] = ['old' => data_get($locked, 'label'), 'new' => $label];
                $locked->forceFill(['label' => $label])->save();
            }

            $locked->syncPermissions($permissions);
            AuditLog::record('role.updated', $locked, $changes, $actor->id);

            return [$locked, 'Izin role '.self::roleLabel($locked).' diubah: +'.count($changes['added']).' / -'.count($changes['removed'])];
        });
    }

    public function deleteRole(Role $role, User $actor): void
    {
        if (PermissionCatalog::isSystemRole($role->name)) {
            throw ValidationException::withMessages(['role' => 'Role bawaan tidak bisa dihapus.']);
        }

        $this->changed($actor, 'role.deleted', function () use ($role, $actor) {
            $locked = Role::whereKey($role->id)->lockForUpdate()->firstOrFail();

            // Akun tanpa role dianggap pelanggan, jadi role yang masih dipakai tidak boleh dihapus.
            if (User::role($locked->name)->exists()) {
                throw ValidationException::withMessages(['role' => 'Role ini masih dipakai akun staf. Pindahkan akunnya ke role lain dulu.']);
            }

            $label = self::roleLabel($locked);
            AuditLog::record('role.deleted', $locked, ['name' => $locked->name, 'label' => $label, 'permissions' => $locked->permissions()->pluck('name')->all()], $actor->id);
            $locked->delete();

            return [null, "Role dihapus: {$label}"];
        });
    }

    public function createPermission(string $name, string $label, ?string $description, User $actor): Permission
    {
        return $this->changed($actor, 'permission.created', function () use ($name, $label, $description, $actor) {
            if (Permission::where('name', $name)->exists() || PermissionCatalog::isSystem($name)) {
                throw ValidationException::withMessages(['name' => 'Kode izin sudah dipakai.']);
            }

            $permission = (new Permission)->forceFill(['name' => $name, 'guard_name' => 'web', 'label' => $label, 'description' => $description]);
            $permission->save();
            Role::findByName(PermissionCatalog::SUPER_ADMIN, 'web')->givePermissionTo($permission);
            AuditLog::record('permission.created', $permission, ['name' => $name, 'label' => $label, 'description' => $description], $actor->id);

            return [$permission, "Izin kustom dibuat: {$label} ({$name})"];
        });
    }

    public function updatePermission(Permission $permission, string $label, ?string $description, User $actor): Permission
    {
        $this->assertCustom($permission);

        return $this->changed($actor, 'permission.updated', function () use ($permission, $label, $description, $actor) {
            $changes = array_filter([
                'label' => data_get($permission, 'label') !== $label ? ['old' => data_get($permission, 'label'), 'new' => $label] : null,
                'description' => data_get($permission, 'description') !== $description ? ['old' => data_get($permission, 'description'), 'new' => $description] : null,
            ]);
            $permission->forceFill(['label' => $label, 'description' => $description])->save();
            AuditLog::record('permission.updated', $permission, $changes, $actor->id);

            return [$permission, "Izin kustom diubah: {$label}"];
        });
    }

    public function deletePermission(Permission $permission, User $actor): void
    {
        $this->assertCustom($permission);

        $this->changed($actor, 'permission.deleted', function () use ($permission, $actor) {
            $roles = $permission->roles()->pluck('name')->all();
            AuditLog::record('permission.deleted', $permission, ['name' => $permission->name, 'label' => data_get($permission, 'label'), 'roles' => $roles], $actor->id);
            $permission->delete();

            return [null, "Izin kustom dihapus: {$permission->name}"];
        });
    }

    /**
     * Izin yang boleh diberikan ke role selain Super Admin: harus ada, dan bukan izin khusus Super Admin.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function assignable(array $permissions): array
    {
        $permissions = array_values(array_unique($permissions));
        $blocked = array_values(array_intersect($permissions, PermissionCatalog::SUPER_ADMIN_ONLY));

        if ($blocked) {
            throw ValidationException::withMessages(['permissions' => 'Izin khusus Super Admin tidak bisa diberikan ke role lain: '.implode(', ', $blocked).'.']);
        }

        $unknown = array_values(array_diff($permissions, Permission::whereIn('name', $permissions)->pluck('name')->all()));
        if ($unknown) {
            throw ValidationException::withMessages(['permissions' => 'Izin tidak dikenal: '.implode(', ', $unknown).'.']);
        }

        return $permissions;
    }

    /** Role kustom tidak boleh menyamar sebagai role bawaan (mis. label "Super Admin") di daftar pengguna dan profil. */
    private function assertLabel(?string $label): void
    {
        if ($label !== null && in_array(mb_strtolower(trim($label)), array_map('mb_strtolower', PermissionCatalog::SYSTEM_ROLES), true)) {
            throw ValidationException::withMessages(['label' => 'Nama tampilan ini dipakai role bawaan. Pilih nama lain.']);
        }
    }

    private function assertCustom(Permission $permission): void
    {
        if (PermissionCatalog::isSystem($permission->name)) {
            throw ValidationException::withMessages(['permission' => 'Izin bawaan dipakai fitur aplikasi, jadi tidak bisa diubah atau dihapus.']);
        }
    }

    /**
     * Menjalankan perubahan dalam transaksi, membuang cache izin, lalu memberi tahu Super Admin lain (peringatan keamanan, tidak bisa dimatikan).
     *
     * @template T
     *
     * @param  callable(): array{0: T, 1: string}  $change
     * @return T
     */
    private function changed(User $actor, string $action, callable $change): mixed
    {
        [$result, $message] = DB::transaction($change, 3);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->notifier->toRoles([PermissionCatalog::SUPER_ADMIN], new StaffEvent('access_changed', 'access', $action, $message, "Oleh {$actor->name}"), $actor);

        return $result;
    }
}
