<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Kelola gudang: semua peran melihat; Super Admin, Admin Toko, dan Gudang mengubah; ubah saja (tanpa tambah atau hapus), kode tetap, minimal satu gudang aktif. */
class AdminWarehouseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::where('code', 'MLG')->firstOrFail();
    }

    public function test_editor_lists_and_updates_the_warehouse_with_an_audit_entry(): void
    {
        $admin = $this->staff('super-admin');
        $warehouse = $this->warehouse();

        $this->actingAs($admin)->getJson('/api/v1/admin/warehouses')->assertOk()
            ->assertJsonPath('data.items.0.code', 'MLG')
            ->assertJsonPath('data.items.0.shipping_ready', false);

        $this->actingAs($admin)->patchJson("/api/v1/admin/warehouses/{$warehouse->id}", [
            'address' => 'Jl. Contoh No. 1, Malang',
            'postal_code' => '65141',
            'phone' => '081234567890',
        ])->assertOk()->assertJsonPath('data.shipping_ready', true);

        $warehouse->refresh();
        $this->assertSame('65141', $warehouse->postal_code);
        $this->assertSame('MLG', $warehouse->code);
        $this->assertTrue(AuditLog::where('action', 'warehouse.updated')->exists());
    }

    /** @return array<string, array{string}> */
    public static function viewOnlyRoles(): array
    {
        return array_map(fn (string $role) => [$role], ['owner', 'customer-service', 'marketing']);
    }

    #[DataProvider('viewOnlyRoles')]
    public function test_other_roles_can_only_view_warehouses(string $role): void
    {
        $user = $this->staff($role);

        $this->actingAs($user)->getJson('/api/v1/admin/warehouses')->assertOk()->assertJsonPath('data.items.0.code', 'MLG');
        $this->actingAs($user)->patchJson("/api/v1/admin/warehouses/{$this->warehouse()->id}", ['name' => 'X'])->assertForbidden();
        $this->assertNotSame('X', $this->warehouse()->name);
    }

    /** @return array<string, array{string}> */
    public static function editorRoles(): array
    {
        return array_map(fn (string $role) => [$role], ['super-admin', 'store-admin', 'warehouse']);
    }

    #[DataProvider('editorRoles')]
    public function test_super_admin_store_admin_and_warehouse_can_edit(string $role): void
    {
        $this->actingAs($this->staff($role))
            ->patchJson("/api/v1/admin/warehouses/{$this->warehouse()->id}", ['postal_code' => '65141'])
            ->assertOk();

        $this->assertSame('65141', $this->warehouse()->postal_code);
    }

    public function test_code_cannot_be_changed_and_input_is_validated(): void
    {
        $admin = $this->staff('super-admin');
        $url = "/api/v1/admin/warehouses/{$this->warehouse()->id}";

        $this->actingAs($admin)->patchJson($url, ['code' => 'XXX'])->assertOk();
        $this->assertSame('MLG', $this->warehouse()->code);

        $this->actingAs($admin)->patchJson($url, ['postal_code' => '12'])->assertJsonValidationErrors('postal_code');
        $this->actingAs($admin)->patchJson($url, ['phone' => 'abc'])->assertJsonValidationErrors('phone');
        $this->actingAs($admin)->patchJson($url, ['latitude' => 120])->assertJsonValidationErrors('latitude');
    }

    public function test_the_last_active_warehouse_cannot_be_deactivated(): void
    {
        $admin = $this->staff('super-admin');
        $malang = $this->warehouse();

        $this->actingAs($admin)->patchJson("/api/v1/admin/warehouses/{$malang->id}", ['is_active' => false])->assertJsonValidationErrors('is_active');
        $this->assertTrue($malang->refresh()->is_active);

        $jakarta = Warehouse::create(['code' => 'JKT', 'name' => 'Gudang Jakarta', 'city' => 'Jakarta', 'is_active' => true]);
        $this->actingAs($admin)->patchJson("/api/v1/admin/warehouses/{$malang->id}", ['is_active' => false])->assertOk();
        $this->assertFalse($malang->refresh()->is_active);

        $this->actingAs($admin)->patchJson("/api/v1/admin/warehouses/{$jakarta->id}", ['is_active' => false])->assertJsonValidationErrors('is_active');
    }

    public function test_staff_without_mfa_cannot_write(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $this->actingAs($user)->patchJson("/api/v1/admin/warehouses/{$this->warehouse()->id}", ['name' => 'X'])->assertForbidden();
    }
}
