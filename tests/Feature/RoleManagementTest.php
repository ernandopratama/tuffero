<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PermissionCatalog;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * CRUD role dan izin oleh Super Admin (D-129): hanya Super Admin, kata sandi wajib, role Super Admin terkunci,
 * izin khusus Super Admin tidak bisa diberikan ke role lain, bawaan tidak bisa dihapus, perubahan langsung berlaku.
 */
class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Rahasia-Uji-123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now(), 'password' => self::PASSWORD]);
        $user->assignRole($role);

        return $user;
    }

    private function role(string $name): Role
    {
        return Role::findByName($name, 'web');
    }

    public function test_catalog_covers_every_permission_checked_by_routes_and_seeded(): void
    {
        $used = collect(Route::getRoutes()->getRoutes())
            ->flatMap(fn ($route) => $route->gatherMiddleware())
            ->filter(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'))
            ->flatMap(fn (string $m) => explode('|', substr($m, 11)))
            ->merge(Permission::pluck('name'))
            ->unique();

        $this->assertSame([], $used->reject(fn (string $name) => PermissionCatalog::isSystem($name))->values()->all());
    }

    /** @return array<string, array{string}> */
    public static function nonSuperAdmins(): array
    {
        return ['owner' => ['owner'], 'store-admin' => ['store-admin'], 'customer-service' => ['customer-service'], 'warehouse' => ['warehouse'], 'marketing' => ['marketing']];
    }

    #[DataProvider('nonSuperAdmins')]
    public function test_only_super_admin_reaches_role_endpoints(string $role): void
    {
        $user = $this->staff($role);
        $target = $this->role('marketing');

        $this->actingAs($user)->getJson('/api/v1/admin/access')->assertForbidden();
        $this->actingAs($user)->postJson('/api/v1/admin/roles', ['password' => self::PASSWORD, 'name' => 'tamu', 'label' => 'Tamu', 'permissions' => []])->assertForbidden();
        $this->actingAs($user)->patchJson("/api/v1/admin/roles/{$target->id}", ['password' => self::PASSWORD, 'add' => [], 'remove' => ['news.view']])->assertForbidden();
        $this->actingAs($user)->postJson('/api/v1/admin/permissions', ['password' => self::PASSWORD, 'name' => 'laporan.export', 'label' => 'Ekspor'])->assertForbidden();
        $this->assertTrue($target->fresh()->permissions()->exists());
    }

    public function test_changes_require_the_current_password(): void
    {
        $admin = $this->staff('super-admin');
        $role = $this->role('marketing');

        $this->actingAs($admin)->patchJson("/api/v1/admin/roles/{$role->id}", ['add' => [], 'remove' => ['news.view']])->assertJsonValidationErrors('password');
        $this->actingAs($admin)->patchJson("/api/v1/admin/roles/{$role->id}", ['password' => 'salah-sekali', 'add' => [], 'remove' => ['news.view']])->assertJsonValidationErrors('password');
        $this->assertTrue($role->fresh()->permissions()->exists());
    }

    public function test_super_admin_role_is_locked(): void
    {
        $admin = $this->staff('super-admin');
        $role = $this->role('super-admin');

        $this->actingAs($admin)->patchJson("/api/v1/admin/roles/{$role->id}", ['password' => self::PASSWORD, 'add' => [], 'remove' => ['orders.view']])->assertJsonValidationErrors('role');
        $this->actingAs($admin)->deleteJson("/api/v1/admin/roles/{$role->id}", ['password' => self::PASSWORD])->assertJsonValidationErrors('role');
        $this->assertSame(Permission::count(), $role->fresh()->permissions()->count());
    }

    public function test_super_admin_only_permissions_cannot_be_granted_to_other_roles(): void
    {
        $admin = $this->staff('super-admin');
        $role = $this->role('store-admin');

        foreach (PermissionCatalog::SUPER_ADMIN_ONLY as $permission) {
            $this->actingAs($admin)->patchJson("/api/v1/admin/roles/{$role->id}", ['password' => self::PASSWORD, 'add' => ['orders.view', $permission], 'remove' => []])->assertJsonValidationErrors('permissions');
            $this->actingAs($admin)->postJson('/api/v1/admin/roles', ['password' => self::PASSWORD, 'name' => 'naik-'.str_replace(['.', '_'], '-', $permission), 'label' => 'Naik', 'permissions' => [$permission]])->assertJsonValidationErrors('permissions');
            $this->assertFalse($role->fresh()->hasPermissionTo($permission));
        }

        $this->actingAs($admin)->patchJson("/api/v1/admin/roles/{$role->id}", ['password' => self::PASSWORD, 'add' => ['tidak.ada'], 'remove' => []])->assertJsonValidationErrors('permissions');
    }

    public function test_removing_a_permission_takes_effect_immediately_and_is_audited(): void
    {
        $admin = $this->staff('super-admin');
        $otherAdmin = $this->staff('super-admin');
        $storeAdmin = $this->staff('store-admin');
        $role = $this->role('store-admin');
        $this->actingAs($storeAdmin)->getJson('/api/v1/admin/orders')->assertOk();

        $this->actingAs($admin)->patchJson("/api/v1/admin/roles/{$role->id}", ['password' => self::PASSWORD, 'add' => [], 'remove' => ['orders.view']])->assertOk();

        $this->actingAs($storeAdmin->fresh())->getJson('/api/v1/admin/orders')->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.updated', 'auditable_id' => $role->id, 'actor_user_id' => $admin->id]);
        $this->assertSame(1, $otherAdmin->notifications()->count());
        $this->assertSame(0, $admin->notifications()->count());

        // Seeder menyinkronkan role bawaan ke matriks (diminta Ernando): izin yang dicabut dari dashboard kembali dan harus diatur ulang.
        $this->seed(RolePermissionSeeder::class);
        $this->assertTrue($role->fresh()->hasPermissionTo('orders.view'));
    }

    public function test_seeder_sync_leaves_custom_roles_and_permissions_alone(): void
    {
        $admin = $this->staff('super-admin');
        $this->actingAs($admin)->postJson('/api/v1/admin/permissions', ['password' => self::PASSWORD, 'name' => 'laporan.export', 'label' => 'Ekspor laporan'])->assertCreated();
        $this->actingAs($admin)->postJson('/api/v1/admin/roles', ['password' => self::PASSWORD, 'name' => 'admin-cabang', 'label' => 'Admin Cabang', 'permissions' => ['orders.view', 'laporan.export']])->assertCreated();

        $this->seed(RolePermissionSeeder::class);

        $custom = $this->role('admin-cabang');
        $this->assertTrue($custom->hasPermissionTo('laporan.export'));
        $this->assertSame(['laporan.export', 'orders.view'], $custom->permissions->pluck('name')->sort()->values()->all());
        $this->assertTrue(Permission::where('name', 'laporan.export')->exists());
    }

    public function test_custom_role_lifecycle(): void
    {
        $admin = $this->staff('super-admin');

        $id = $this->actingAs($admin)->postJson('/api/v1/admin/roles', ['password' => self::PASSWORD, 'name' => 'admin-cabang', 'label' => 'Admin Cabang', 'permissions' => ['orders.view']])
            ->assertCreated()->json('data.id');
        $this->actingAs($admin)->postJson('/api/v1/admin/roles', ['password' => self::PASSWORD, 'name' => 'store-admin', 'label' => 'Kembar', 'permissions' => []])->assertJsonValidationErrors('name');

        $staff = $this->staff('admin-cabang');
        $this->actingAs($staff)->getJson('/api/v1/admin/orders')->assertOk();
        $this->actingAs($staff)->getJson('/api/v1/admin/products')->assertForbidden();
        $this->actingAs($staff)->getJson('/api/v1/auth/user')->assertJsonPath('data.user.role_label', 'Admin Cabang');
        $this->actingAs($staff)->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.role', 'admin-cabang');

        // Masih dipakai akun: tidak bisa dihapus (akun tanpa role dianggap pelanggan).
        $this->actingAs($admin)->deleteJson("/api/v1/admin/roles/{$id}", ['password' => self::PASSWORD])->assertJsonValidationErrors('role');
        $staff->syncRoles(['store-admin']);
        $this->actingAs($admin)->deleteJson("/api/v1/admin/roles/{$id}", ['password' => self::PASSWORD])->assertOk();
        $this->assertNull(Role::find($id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.deleted', 'auditable_id' => $id]);
    }

    public function test_system_roles_cannot_be_deleted(): void
    {
        $admin = $this->staff('super-admin');

        foreach (array_keys(PermissionCatalog::SYSTEM_ROLES) as $name) {
            $this->actingAs($admin)->deleteJson('/api/v1/admin/roles/'.$this->role($name)->id, ['password' => self::PASSWORD])->assertJsonValidationErrors('role');
        }
    }

    public function test_custom_permission_lifecycle_and_system_permissions_are_protected(): void
    {
        $admin = $this->staff('super-admin');

        $this->actingAs($admin)->postJson('/api/v1/admin/permissions', ['password' => self::PASSWORD, 'name' => 'Laporan Export', 'label' => 'Ekspor'])->assertJsonValidationErrors('name');
        $this->actingAs($admin)->postJson('/api/v1/admin/permissions', ['password' => self::PASSWORD, 'name' => 'orders.view', 'label' => 'Kembar'])->assertJsonValidationErrors('name');
        $id = $this->actingAs($admin)->postJson('/api/v1/admin/permissions', ['password' => self::PASSWORD, 'name' => 'laporan.export', 'label' => 'Ekspor laporan', 'description' => '<b>uji</b>'])
            ->assertCreated()->json('data.id');

        $permission = Permission::findOrFail($id);
        $this->assertSame('uji', $permission->description);
        $this->assertTrue($this->role('super-admin')->hasPermissionTo('laporan.export'));

        $this->actingAs($admin)->patchJson("/api/v1/admin/roles/{$this->role('marketing')->id}", ['password' => self::PASSWORD, 'add' => ['laporan.export'], 'remove' => []])->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/admin/permissions/{$id}", ['password' => self::PASSWORD, 'label' => 'Ekspor data'])->assertOk();
        $this->actingAs($admin)->deleteJson("/api/v1/admin/permissions/{$id}", ['password' => self::PASSWORD])->assertOk();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertNull(Permission::where('name', 'laporan.export')->first());

        $system = Permission::findByName('orders.view', 'web');
        $this->actingAs($admin)->patchJson("/api/v1/admin/permissions/{$system->id}", ['password' => self::PASSWORD, 'label' => 'Ganti'])->assertJsonValidationErrors('permission');
        $this->actingAs($admin)->deleteJson("/api/v1/admin/permissions/{$system->id}", ['password' => self::PASSWORD])->assertJsonValidationErrors('permission');
        $this->assertNotNull(Permission::where('name', 'orders.view')->first());
    }

    public function test_overview_lists_roles_with_labels_and_flags(): void
    {
        $data = $this->actingAs($this->staff('super-admin'))->getJson('/api/v1/admin/access')->assertOk()->json('data');

        $super = collect($data['roles'])->firstWhere('name', 'super-admin');
        $this->assertTrue($super['locked']);
        $this->assertSame('Super Admin', $super['label']);
        $this->assertTrue(collect($data['permissions'])->firstWhere('name', 'users.manage')['super_admin_only']);
        $this->assertSame('Pesanan', collect($data['permissions'])->firstWhere('name', 'orders.view')['group']);
    }

    public function test_staff_cannot_promote_themselves_through_the_user_endpoint(): void
    {
        $storeAdmin = $this->staff('store-admin');

        $this->actingAs($storeAdmin)->patchJson("/api/v1/admin/users/{$storeAdmin->id}", ['role' => 'super-admin'])->assertForbidden();
        $this->assertFalse($storeAdmin->fresh()->hasRole('super-admin'));
    }

    public function test_saving_a_stale_page_does_not_undo_another_super_admins_change(): void
    {
        $first = $this->staff('super-admin');
        $second = $this->staff('super-admin');
        $role = $this->role('customer-service');

        // Keduanya membuka halaman dengan izin yang sama; yang pertama menambah, yang kedua mencabut izin lain.
        $this->actingAs($first)->patchJson("/api/v1/admin/roles/{$role->id}", ['password' => self::PASSWORD, 'add' => ['vouchers.view'], 'remove' => []])->assertOk();
        $this->actingAs($second)->patchJson("/api/v1/admin/roles/{$role->id}", ['password' => self::PASSWORD, 'add' => [], 'remove' => ['emails.send']])->assertOk();

        $role = $role->fresh();
        $this->assertTrue($role->hasPermissionTo('vouchers.view'));
        $this->assertFalse($role->hasPermissionTo('emails.send'));
    }

    public function test_look_alike_permission_names_are_rejected(): void
    {
        $admin = $this->staff('super-admin');
        $role = $this->role('store-admin');

        foreach (['Users.Manage', 'USERS.MANAGE', 'roles.Manage'] as $name) {
            $this->actingAs($admin)->patchJson("/api/v1/admin/roles/{$role->id}", ['password' => self::PASSWORD, 'add' => [$name], 'remove' => []])->assertJsonValidationErrors('permissions');
        }
        $this->assertFalse($role->fresh()->hasPermissionTo('users.manage'));
        $this->assertFalse($role->fresh()->hasPermissionTo('roles.manage'));
    }

    public function test_custom_role_cannot_borrow_a_built_in_label(): void
    {
        $admin = $this->staff('super-admin');

        $this->actingAs($admin)->postJson('/api/v1/admin/roles', ['password' => self::PASSWORD, 'name' => 'super-admln', 'label' => ' super admin ', 'permissions' => []])->assertJsonValidationErrors('label');
        $id = $this->actingAs($admin)->postJson('/api/v1/admin/roles', ['password' => self::PASSWORD, 'name' => 'admin-cabang', 'label' => 'Admin Cabang', 'permissions' => []])->assertCreated()->json('data.id');
        $this->actingAs($admin)->patchJson("/api/v1/admin/roles/{$id}", ['password' => self::PASSWORD, 'label' => 'Pemilik', 'add' => [], 'remove' => []])->assertJsonValidationErrors('label');
        $this->actingAs($admin)->patchJson("/api/v1/admin/roles/{$id}", ['password' => self::PASSWORD, 'label' => 'Admin Cabang Batu', 'add' => [], 'remove' => []])->assertOk();
        $this->assertSame('Admin Cabang Batu', Role::findOrFail($id)->label);
    }

    public function test_super_admin_keeps_every_permission_after_seeder_reruns(): void
    {
        $admin = $this->staff('super-admin');
        $this->actingAs($admin)->postJson('/api/v1/admin/permissions', ['password' => self::PASSWORD, 'name' => 'laporan.export', 'label' => 'Ekspor laporan'])->assertCreated();

        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(Permission::count(), $this->role('super-admin')->permissions()->count());
        $this->assertTrue($this->role('super-admin')->hasPermissionTo('laporan.export'));
    }

    public function test_missing_defaults_are_only_added_never_removed(): void
    {
        $role = $this->role('store-admin');
        $role->revokePermissionTo('orders.view');
        $role->givePermissionTo('reports.view');

        (new RolePermissionSeeder)->grantMissingDefaults();

        $role = $role->fresh();
        $this->assertTrue($role->hasPermissionTo('orders.view'));
        $this->assertTrue($role->hasPermissionTo('reports.view'));
    }

    public function test_writes_require_mfa_enrollment_outside_local(): void
    {
        $admin = User::factory()->create(['mfa_totp_confirmed_at' => null, 'password' => self::PASSWORD]);
        $admin->assignRole('super-admin');
        $role = $this->role('marketing');

        $this->actingAs($admin)->patchJson("/api/v1/admin/roles/{$role->id}", ['password' => self::PASSWORD, 'add' => [], 'remove' => ['news.view']])->assertForbidden();
        $this->assertTrue($role->fresh()->hasPermissionTo('news.view'));
    }

    public function test_non_super_admin_cannot_create_a_super_admin_or_read_roles(): void
    {
        $storeAdmin = $this->staff('store-admin');

        $this->actingAs($storeAdmin)->postJson('/api/v1/admin/users', ['name' => 'Penyusup', 'email' => 'penyusup@example.com', 'password' => 'Rahasia-Uji-12345', 'role' => 'super-admin'])->assertForbidden();
        $this->actingAs($storeAdmin)->getJson('/api/v1/admin/roles')->assertForbidden();
        $this->assertNull(User::where('email', 'penyusup@example.com')->first());
    }

    public function test_permission_create_and_delete_notify_other_super_admins(): void
    {
        $admin = $this->staff('super-admin');
        $other = $this->staff('super-admin');

        $id = $this->actingAs($admin)->postJson('/api/v1/admin/permissions', ['password' => self::PASSWORD, 'name' => 'laporan.export', 'label' => 'Ekspor laporan'])->assertCreated()->json('data.id');
        $this->actingAs($admin)->deleteJson("/api/v1/admin/permissions/{$id}", ['password' => self::PASSWORD])->assertOk();

        $this->assertSame(2, $other->notifications()->count());
        $this->assertEqualsCanonicalizing(['permission.created', 'permission.deleted'], $other->notifications()->get()->pluck('data.label')->all());
    }
}
