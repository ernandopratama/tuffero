<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG_PASSWORD = 'Test-Only-Password-123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    private function staff(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now(), ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, array{string, string, string, list<mixed>}> */
    public static function superAdminOnlyProvider(): array
    {
        return [
            'lihat pengguna' => ['getJson', '/api/v1/admin/users', 'owner', []],
            'lihat role' => ['getJson', '/api/v1/admin/roles', 'store-admin', []],
            'buat pengguna' => ['postJson', '/api/v1/admin/users', 'owner', []],
            'lihat pengaturan' => ['getJson', '/api/v1/admin/settings', 'owner', []],
            'ubah pengaturan' => ['patchJson', '/api/v1/admin/settings/contact.admin_email', 'marketing', ['value' => 'x@example.com']],
            'audit bukan untuk gudang' => ['getJson', '/api/v1/admin/audit-logs', 'warehouse', []],
        ];
    }

    #[DataProvider('superAdminOnlyProvider')]
    public function test_other_roles_are_forbidden(string $method, string $url, string $role, array $body): void
    {
        $this->actingAs($this->staff($role))->{$method}($url, $body)->assertForbidden();
    }

    public function test_admin_api_requires_login_and_a_role(): void
    {
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_owner_can_read_audit_log(): void
    {
        $this->actingAs($this->staff('owner'))->getJson('/api/v1/admin/audit-logs')->assertOk();
    }

    public function test_writes_require_enrolled_mfa_outside_local(): void
    {
        $admin = $this->staff('super-admin', ['mfa_totp_confirmed_at' => null]);

        $this->actingAs($admin)->getJson('/api/v1/admin/users')->assertOk();
        $this->actingAs($admin)->patchJson('/api/v1/admin/settings/contact.admin_email', ['value' => 'x@example.com'])->assertForbidden();
    }

    public function test_super_admin_creates_user_and_audit_has_no_password(): void
    {
        $admin = $this->staff('super-admin');

        $this->actingAs($admin)->postJson('/api/v1/admin/users', [
            'name' => 'Staf Baru',
            'email' => 'staf@example.com',
            'password' => self::STRONG_PASSWORD,
            'role' => 'warehouse',
        ])->assertCreated()->assertJsonPath('data.role', 'warehouse')->assertJsonMissingPath('data.password');

        $user = User::where('email', 'staf@example.com')->firstOrFail();
        $log = AuditLog::where('action', 'user.created')->firstOrFail();

        $this->assertTrue($user->hasRole('warehouse'));
        $this->assertTrue($user->mfa_required ?? true);
        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $user->password));
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertStringNotContainsString(self::STRONG_PASSWORD, (string) json_encode($log->changes));
    }

    public function test_create_rejects_weak_password_duplicate_email_and_unknown_role(): void
    {
        $admin = $this->staff('super-admin');

        $this->actingAs($admin)->postJson('/api/v1/admin/users', [
            'name' => 'X',
            'email' => $admin->email,
            'password' => 'lemah',
            'role' => 'root',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password', 'role']);
    }

    public function test_cannot_mass_assign_protected_fields_on_create(): void
    {
        $this->actingAs($this->staff('super-admin'))->postJson('/api/v1/admin/users', [
            'name' => 'Staf',
            'email' => 'staf2@example.com',
            'password' => self::STRONG_PASSWORD,
            'role' => 'marketing',
            'mfa_required' => false,
            'is_active' => false,
        ])->assertCreated();

        $user = User::where('email', 'staf2@example.com')->firstOrFail()->fresh();

        $this->assertTrue($user->mfa_required);
        $this->assertTrue($user->is_active);
    }

    public function test_update_changes_role_status_and_records_old_and_new(): void
    {
        $admin = $this->staff('super-admin');
        $target = $this->staff('marketing');

        $this->actingAs($admin)->patchJson("/api/v1/admin/users/{$target->id}", ['role' => 'owner', 'mfa_required' => false])
            ->assertOk()
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.mfa_required', false);

        $changes = AuditLog::where('action', 'user.updated')->firstOrFail()->changes;

        $this->assertSame(['old' => 'marketing', 'new' => 'owner'], $changes['role']);
        $this->assertFalse($changes['mfa_required']['new']);
        $this->assertFalse($target->fresh()->hasRole('marketing'));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function selfChangeProvider(): array
    {
        return [
            'role' => [['role' => 'marketing'], 'role'],
            'nonaktif' => [['is_active' => false], 'is_active'],
            'mfa' => [['mfa_required' => false], 'mfa_required'],
        ];
    }

    #[DataProvider('selfChangeProvider')]
    public function test_super_admin_cannot_weaken_own_account(array $body, string $field): void
    {
        $admin = $this->staff('super-admin');

        $this->actingAs($admin)->patchJson("/api/v1/admin/users/{$admin->id}", $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertTrue($admin->fresh()->hasRole('super-admin'));
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_super_admin_cannot_reset_own_mfa(): void
    {
        $admin = $this->staff('super-admin');

        $this->actingAs($admin)->postJson("/api/v1/admin/users/{$admin->id}/reset-mfa")->assertUnprocessable();
        $this->assertNotNull($admin->fresh()->mfa_totp_confirmed_at);
    }

    public function test_reset_mfa_of_other_user_is_recorded(): void
    {
        $admin = $this->staff('super-admin');
        $target = $this->staff('warehouse', ['mfa_totp_secret' => 'GEZDGNBVGY3TQOJQ']);

        $this->actingAs($admin)->postJson("/api/v1/admin/users/{$target->id}/reset-mfa")->assertOk()->assertJsonPath('data.mfa_enrolled', false);

        $this->assertNull($target->fresh()->mfa_totp_secret);
        $this->assertSame($admin->id, AuditLog::where('action', 'mfa.reset')->value('actor_user_id'));
    }

    public function test_username_is_lowercased_unique_and_validated(): void
    {
        $admin = $this->staff('super-admin');
        $body = ['name' => 'Budi', 'email' => 'budi@example.com', 'password' => self::STRONG_PASSWORD, 'role' => 'warehouse'];

        $this->actingAs($admin)->postJson('/api/v1/admin/users', [...$body, 'username' => '  Budi.Gudang '])->assertCreated()->assertJsonPath('data.username', 'budi.gudang');
        $this->actingAs($admin)->postJson('/api/v1/admin/users', [...$body, 'email' => 'b2@example.com', 'username' => 'BUDI.gudang'])->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->actingAs($admin)->postJson('/api/v1/admin/users', [...$body, 'email' => 'b3@example.com', 'username' => 'a@b'])->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->actingAs($admin)->postJson('/api/v1/admin/users', [...$body, 'email' => 'b4@example.com', 'username' => 'ab'])->assertUnprocessable()->assertJsonValidationErrors('username');

        $id = User::where('email', 'budi@example.com')->value('id');
        $this->actingAs($admin)->patchJson("/api/v1/admin/users/{$id}", ['username' => null])->assertOk()->assertJsonPath('data.username', null);
    }

    public function test_super_admin_deletes_staff_and_audit_keeps_identity(): void
    {
        $admin = $this->staff('super-admin');
        $target = $this->staff('warehouse');
        $target->createToken('t');

        $this->actingAs($admin)->deleteJson("/api/v1/admin/users/{$target->id}")->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $target->id]);
        $log = AuditLog::where('action', 'user.deleted')->firstOrFail();
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertSame($target->email, $log->changes['email']);
        $this->assertSame('warehouse', $log->changes['role']);
    }

    public function test_cannot_delete_self_customer_or_user_with_internal_notes(): void
    {
        $admin = $this->staff('super-admin');
        $customer = User::factory()->create();
        $writer = $this->staff('marketing');
        DB::table('admin_comments')->insert(['user_id' => $writer->id, 'commentable_type' => 'x', 'commentable_id' => 1, 'body' => 'catatan', 'created_at' => now()]);

        $this->actingAs($admin)->deleteJson("/api/v1/admin/users/{$admin->id}")->assertUnprocessable();
        $this->actingAs($admin)->deleteJson("/api/v1/admin/users/{$customer->id}")->assertUnprocessable();
        $this->actingAs($admin)->deleteJson("/api/v1/admin/users/{$writer->id}")->assertUnprocessable();

        foreach ([$admin, $customer, $writer] as $user) {
            $this->assertDatabaseHas('users', ['id' => $user->id]);
        }
        $this->assertSame(0, AuditLog::where('action', 'user.deleted')->count());
    }

    public function test_only_super_admin_can_delete_users(): void
    {
        $target = $this->staff('warehouse');

        $this->actingAs($this->staff('owner'))->deleteJson("/api/v1/admin/users/{$target->id}")->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_deactivated_user_loses_existing_session_and_cannot_log_in(): void
    {
        $user = $this->staff('owner', ['password' => Hash::make(self::STRONG_PASSWORD), 'mfa_required' => false]);

        $this->actingAs($user)->getJson('/api/v1/auth/user')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user->fresh())->getJson('/api/v1/auth/user')->assertUnauthorized();
        $this->actingAs($user->fresh())->getJson('/api/v1/admin/audit-logs')->assertUnauthorized();
    }

    public function test_password_change_and_deactivation_drop_database_sessions(): void
    {
        $admin = $this->staff('super-admin');
        $target = $this->staff('marketing');
        $row = ['user_id' => $target->id, 'payload' => '', 'last_activity' => time()];
        DB::table('sessions')->insert(['id' => 'sesi-uji', ...$row]);

        $this->actingAs($admin);
        config(['session.driver' => 'database']);

        $this->patchJson("/api/v1/admin/users/{$target->id}", ['password' => self::STRONG_PASSWORD])->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'sesi-uji']);
        $this->assertTrue(AuditLog::where('action', 'user.updated')->firstOrFail()->changes['password_changed']);
    }

    public function test_setting_update_validates_and_records_old_and_new_values(): void
    {
        $admin = $this->staff('super-admin');
        $old = Setting::where('key', 'contact.whatsapp_number')->value('value');

        $this->actingAs($admin)->patchJson('/api/v1/admin/settings/contact.whatsapp_number', ['value' => '0812345'])
            ->assertUnprocessable();
        $this->assertSame($old, Setting::where('key', 'contact.whatsapp_number')->value('value'));

        $this->patchJson('/api/v1/admin/settings/contact.whatsapp_number', ['value' => '+6281234567890'])->assertOk();

        $log = AuditLog::where('action', 'setting.updated')->firstOrFail();
        $this->assertSame($old, $log->changes['old']);
        $this->assertSame('+6281234567890', $log->changes['new']);
        $this->assertSame($admin->id, Setting::where('key', 'contact.whatsapp_number')->value('updated_by_user_id'));
    }

    public function test_setting_rules_reject_invalid_values_and_unknown_keys(): void
    {
        $this->actingAs($this->staff('super-admin'));

        $this->patchJson('/api/v1/admin/settings/contact.admin_email', ['value' => 'bukan-email'])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/settings/refund.claim_window_hours', ['value' => 0])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/settings/refund.claim_window_hours', ['value' => 'abc'])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/settings/tidak.ada', ['value' => 'x'])->assertNotFound();
    }

    public function test_store_admin_manages_contact_settings_only(): void
    {
        $admin = $this->staff('store-admin');

        $keys = collect($this->actingAs($admin)->getJson('/api/v1/admin/settings')->assertOk()->json('data.settings'))->pluck('key');
        $this->assertTrue($keys->every(fn (string $key) => str_starts_with($key, 'contact.') || str_starts_with($key, 'reviews.') || str_starts_with($key, 'discount.')));
        $this->assertContains('contact.whatsapp_number', $keys->all());

        $this->patchJson('/api/v1/admin/settings/contact.whatsapp_number', ['value' => '+6281231481755'])->assertOk();
        $this->patchJson('/api/v1/admin/settings/contact.whatsapp_number', ['value' => '0812345'])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/settings/checkout.reservation_minutes', ['value' => 30])->assertForbidden();
        $this->patchJson('/api/v1/admin/settings/refund.claim_window_hours', ['value' => 24])->assertForbidden();
    }

    public function test_public_settings_expose_only_public_values_without_login(): void
    {
        $settings = $this->getJson('/api/v1/settings/public')->assertOk()->json('data.settings');

        $this->assertSame('+6281231481755', $settings['contact.whatsapp_number']);
        $this->assertArrayHasKey('contact.admin_email', $settings);
        $this->assertArrayNotHasKey('checkout.reservation_minutes', $settings);
        $this->assertArrayNotHasKey('refund.claim_window_hours', $settings);
    }

    public function test_audit_log_filters_and_hides_user_agent(): void
    {
        $admin = $this->staff('super-admin');
        $this->actingAs($admin)->patchJson('/api/v1/admin/settings/contact.admin_email', ['value' => 'baru@example.com'])->assertOk();

        $this->getJson('/api/v1/admin/audit-logs?action=setting.updated')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.actor', $admin->name)
            ->assertJsonMissingPath('data.items.0.user_agent');

        $this->getJson('/api/v1/admin/audit-logs?action=user.created')->assertJsonPath('data.meta.total', 0);
    }

    public function test_users_list_excludes_customers(): void
    {
        $admin = $this->staff('super-admin');
        $customer = User::factory()->create();

        $emails = collect($this->actingAs($admin)->getJson('/api/v1/admin/users')->assertOk()->json('data.items'))->pluck('email');

        $this->assertTrue($emails->contains($admin->email));
        $this->assertFalse($emails->contains($customer->email));
    }

    public function test_users_list_supports_search_and_hides_secrets(): void
    {
        $admin = $this->staff('super-admin');
        $this->staff('marketing', ['name' => 'Budi Pemasar', 'mfa_totp_secret' => 'GEZDGNBVGY3TQOJQ']);

        $this->actingAs($admin)->getJson('/api/v1/admin/users?search=Pemasar')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.role', 'marketing')
            ->assertJsonPath('data.items.0.mfa_enrolled', true)
            ->assertJsonMissingPath('data.items.0.mfa_totp_secret')
            ->assertJsonMissingPath('data.items.0.password');
    }

    public function test_user_list_filters_by_role_and_active_status(): void
    {
        $admin = $this->staff('super-admin');
        $this->staff('marketing', ['email' => 'mkt-off@example.test', 'is_active' => false]);

        $by = fn (string $query) => collect($this->actingAs($admin)->getJson("/api/v1/admin/users?{$query}")->assertOk()->json('data.items'))->pluck('email');

        $this->assertSame(['mkt-off@example.test'], $by('role=marketing')->all());
        $this->assertSame(['mkt-off@example.test'], $by('is_active=0')->all());
        $this->assertNotContains('mkt-off@example.test', $by('is_active=1'));
    }
}
