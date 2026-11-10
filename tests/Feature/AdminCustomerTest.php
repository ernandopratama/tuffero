<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminCustomerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function staff(string $role): User
    {
        return User::factory()->create(['mfa_totp_confirmed_at' => now()])->assignRole($role);
    }

    private function customer(array $attributes = []): User
    {
        return User::factory()->create(['phone' => '+628110000009', ...$attributes]);
    }

    /** @return array<string, array{string, bool, bool}> */
    public static function roles(): array
    {
        return [
            'super admin' => ['super-admin', true, true],
            'pemilik' => ['owner', true, true],
            'admin toko' => ['store-admin', true, false],
            'customer service' => ['customer-service', false, false],
            'gudang' => ['warehouse', false, false],
            'marketing' => ['marketing', false, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_access_follows_role_matrix(string $role, bool $canView, bool $canDeactivate): void
    {
        $staff = $this->staff($role);
        $customer = $this->customer();

        $this->actingAs($staff)->getJson('/api/v1/admin/customers')->assertStatus($canView ? 200 : 403);
        $this->actingAs($staff)->getJson("/api/v1/admin/customers/{$customer->id}")->assertStatus($canView ? 200 : 403);
        $this->actingAs($staff)->patchJson("/api/v1/admin/customers/{$customer->id}", ['is_active' => false])->assertStatus($canDeactivate ? 200 : 403);

        $this->assertSame(! $canDeactivate, $customer->fresh()->is_active);
    }

    public function test_guest_and_customer_are_rejected(): void
    {
        $customer = $this->customer();

        $this->getJson('/api/v1/admin/customers')->assertUnauthorized();
        $this->actingAs($customer)->getJson('/api/v1/admin/customers')->assertForbidden();
    }

    public function test_list_filters_by_active_status(): void
    {
        $admin = $this->staff('store-admin');
        $this->customer();
        $inactive = $this->customer(['phone' => '+628110000008', 'is_active' => false]);

        $this->actingAs($admin)->getJson('/api/v1/admin/customers?is_active=0')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $inactive->id);
    }

    public function test_list_contains_customers_only_and_searches_phone(): void
    {
        $admin = $this->staff('store-admin');
        $customer = $this->customer();
        $other = $this->customer(['phone' => '+628110000008']);

        $items = collect($this->actingAs($admin)->getJson('/api/v1/admin/customers')->assertOk()->json('data.items'));
        $this->assertEqualsCanonicalizing([$customer->id, $other->id], $items->pluck('id')->all());

        $this->actingAs($admin)->getJson('/api/v1/admin/customers?search=0000008')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $other->id);
    }

    public function test_staff_accounts_are_not_reachable_as_customers(): void
    {
        $admin = $this->staff('owner');
        $staff = $this->staff('warehouse');

        $this->actingAs($admin)->getJson("/api/v1/admin/customers/{$staff->id}")->assertNotFound();
        $this->actingAs($admin)->patchJson("/api/v1/admin/customers/{$staff->id}", ['is_active' => false])->assertNotFound();
        $this->assertTrue($staff->fresh()->is_active);
    }

    public function test_detail_hides_secrets_and_is_audited(): void
    {
        $admin = $this->staff('owner');
        $customer = $this->customer();
        $customer->addresses()->create(['label' => 'Rumah', 'recipient_name' => 'Budi', 'phone' => '+628110000009', 'street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111']);

        $response = $this->actingAs($admin)->getJson("/api/v1/admin/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.addresses.0.street', 'Jl. Uji 1');

        foreach (['password', 'remember_token', 'mfa_totp_secret'] as $secret) {
            $this->assertStringNotContainsString("\"{$secret}\"", $response->getContent());
        }
        $this->assertTrue(AuditLog::where('action', 'customer.viewed')->where('auditable_id', $customer->id)->where('actor_user_id', $admin->id)->exists());
    }

    public function test_update_only_changes_active_status_and_ends_sessions(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->staff('owner');
        $customer = $this->customer();
        DB::table('sessions')->insert(['id' => 'sesi-pelanggan', 'user_id' => $customer->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);

        $this->actingAs($admin)->patchJson("/api/v1/admin/customers/{$customer->id}", [
            'is_active' => false,
            'email' => 'penyerang@tuffero.test',
            'phone' => '+628110000007',
            'role' => 'super-admin',
        ])->assertOk()->assertJsonPath('data.is_active', false);

        $fresh = $customer->fresh();
        $this->assertSame($customer->email, $fresh->email);
        $this->assertSame('+628110000009', $fresh->phone);
        $this->assertTrue($fresh->getRoleNames()->isEmpty());
        $this->assertDatabaseMissing('sessions', ['id' => 'sesi-pelanggan']);
        $this->assertTrue(AuditLog::where('action', 'user.updated')->where('auditable_id', $customer->id)->exists());
    }

    public function test_update_requires_enrolled_mfa_outside_local(): void
    {
        $admin = User::factory()->create(['mfa_totp_confirmed_at' => null])->assignRole('owner');
        $customer = $this->customer();

        $this->actingAs($admin)->patchJson("/api/v1/admin/customers/{$customer->id}", ['is_active' => false])->assertForbidden();
        $this->assertTrue($customer->fresh()->is_active);
    }
}
