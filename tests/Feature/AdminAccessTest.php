<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Test-Only-Password-123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    private function staff(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD), ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, array{string, string, bool}> */
    public static function matrixProvider(): array
    {
        return [
            'hanya super admin mengelola akun' => ['super-admin', 'users.manage', true],
            'pemilik tidak mengelola akun' => ['owner', 'users.manage', false],
            'pemilik melihat konten' => ['owner', 'content.view', true],
            'pemilik tidak mengubah konten' => ['owner', 'content.manage', false],
            'pemilik melihat pengiriman' => ['owner', 'shipments.view', true],
            'pemilik tidak mengubah pengiriman' => ['owner', 'shipments.manage', false],
            'pemilik memberi ACC kedua' => ['owner', 'orders.cancel_final', true],
            'pemilik tidak memberi ACC pertama' => ['owner', 'orders.cancel', false],
            'admin toko memberi ACC pertama' => ['store-admin', 'orders.cancel', true],
            'admin toko tidak memberi ACC kedua' => ['store-admin', 'orders.cancel_final', false],
            'admin toko tidak melihat konten' => ['store-admin', 'content.view', false],
            'cs mengajukan pembatalan' => ['customer-service', 'orders.cancel_request', true],
            'cs tidak memberi ACC' => ['customer-service', 'orders.cancel', false],
            'cs tidak mengubah stok' => ['customer-service', 'stock.adjust', false],
            'admin toko melihat stok' => ['store-admin', 'stock.view', true],
            'admin toko tidak mengubah stok' => ['store-admin', 'stock.adjust', false],
            'gudang melihat data pengemasan' => ['warehouse', 'orders.view_fulfillment', true],
            'gudang tidak melihat pesanan penuh' => ['warehouse', 'orders.view', false],
            'gudang tidak melihat pembayaran' => ['warehouse', 'payments.view', false],
            'marketing mengelola konten' => ['marketing', 'content.manage', true],
            'marketing hanya laporan ringkas' => ['marketing', 'reports.view_summary', true],
            'marketing tanpa laporan penuh' => ['marketing', 'reports.view', false],
            'marketing tanpa data pelanggan' => ['marketing', 'orders.view', false],
        ];
    }

    public function test_guest_gets_401_on_admin_api_even_without_json_accept_header(): void
    {
        $this->get('/api/v1/admin/orders')->assertUnauthorized();
    }

    #[DataProvider('matrixProvider')]
    public function test_role_matrix(string $role, string $permission, bool $allowed): void
    {
        $this->assertSame($allowed, $this->staff($role)->can($permission));
    }

    public function test_super_admin_holds_every_permission(): void
    {
        $this->assertSame(Permission::count(), $this->staff('super-admin')->getAllPermissions()->count());
    }

    public function test_seeders_can_run_twice_without_overwriting_changed_settings(): void
    {
        Setting::where('key', 'contact.admin_email')->update(['value' => 'changed@example.com']);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame('changed@example.com', Setting::where('key', 'contact.admin_email')->value('value'));
        $this->assertSame('168', Setting::where('key', 'refund.claim_window_hours')->value('value'));
    }

    public function test_demo_accounts_are_refused_outside_local(): void
    {
        $this->expectException(\RuntimeException::class);

        (new DemoAccountSeeder)->run(sys_get_temp_dir().'/tuffero-demo-refused.md');
    }

    public function test_demo_accounts_cover_every_role_and_can_log_in(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $path = tempnam(sys_get_temp_dir(), 'tuffero-demo');

        (new DemoAccountSeeder)->setContainer($this->app)->run($path);
        $file = file_get_contents($path);
        unlink($path);
        $this->app->detectEnvironment(fn () => 'testing');

        foreach (['super-admin', 'owner', 'store-admin', 'customer-service', 'warehouse', 'marketing'] as $role) {
            $this->assertTrue(User::where('email', "{$role}@tuffero.test")->firstOrFail()->hasRole($role));
        }

        preg_match('/`owner@tuffero\.test` \| `owner` \| `([^`]+)`/', $file, $match);

        $this->postJson('/api/v1/auth/login', ['login' => 'owner@tuffero.test', 'password' => $match[1]])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'owner');
        $this->app['auth']->guard('web')->logout();
        $this->postJson('/api/v1/auth/login', ['login' => 'OWNER', 'password' => $match[1]])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'owner');
    }

    public function test_login_accepts_username_and_rejects_wrong_inactive_or_unknown(): void
    {
        $user = $this->staff('marketing', ['username' => 'tim.marketing', 'mfa_required' => false]);
        $inactive = $this->staff('owner', ['username' => 'ex-owner', 'is_active' => false]);

        $this->postJson('/api/v1/auth/login', ['login' => 'tim.marketing', 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('data.user.role', 'marketing');
        $this->app['auth']->guard('web')->logout();

        $wrong = $this->postJson('/api/v1/auth/login', ['login' => 'tim.marketing', 'password' => 'salah']);
        $unknown = $this->postJson('/api/v1/auth/login', ['login' => 'tidak-ada', 'password' => self::PASSWORD]);
        $off = $this->postJson('/api/v1/auth/login', ['login' => 'ex-owner', 'password' => self::PASSWORD]);

        $wrong->assertUnauthorized();
        $this->assertSame($wrong->json(), $unknown->json());
        $this->assertSame($wrong->json(), $off->json());
        $this->assertGuest('web');
    }

    public function test_username_cannot_be_used_with_an_at_sign_to_hijack_email_lookup(): void
    {
        $this->staff('owner', ['username' => 'sama']);

        $this->postJson('/api/v1/auth/login', ['login' => 'sama@tuffero.test', 'password' => self::PASSWORD])->assertUnauthorized();
    }

    public function test_demo_customers_have_no_role_and_no_admin_access(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $path = tempnam(sys_get_temp_dir(), 'tuffero-demo');

        (new DemoAccountSeeder)->setContainer($this->app)->run($path);
        $file = file_get_contents($path);
        unlink($path);
        $this->app->detectEnvironment(fn () => 'testing');

        foreach ([1, 2] as $number) {
            $this->assertCount(0, User::where('email', "pelanggan{$number}@tuffero.test")->firstOrFail()->getRoleNames());
            $this->assertNotNull(User::where('email', "pelanggan{$number}@tuffero.test")->firstOrFail()->email_verified_at);
            $this->assertNotNull(User::where('email', "pelanggan{$number}@tuffero.test")->firstOrFail()->phone_verified_at);
        }

        preg_match('/`pelanggan1@tuffero\.test` \| `pelanggan1` \| `([^`]+)`/', $file, $match);

        $this->postJson('/api/v1/auth/login', ['email' => 'pelanggan1@tuffero.test', 'password' => $match[1]])
            ->assertOk()
            ->assertJsonPath('data.user.role', null)
            ->assertJsonPath('data.user.permissions', []);

        $this->getJson('/api/v1/admin/orders')->assertForbidden();
        $this->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_active_staff_can_log_in_and_receives_permissions(): void
    {
        $user = $this->staff('marketing', ['mfa_required' => false]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'marketing')
            ->assertJsonFragment(['content.manage'])
            ->assertJsonMissingPath('data.user.mfa_totp_secret');
    }

    public function test_inactive_user_gets_same_response_as_wrong_password(): void
    {
        $user = $this->staff('owner', ['is_active' => false]);

        $inactive = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        $wrong = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'salah']);

        $inactive->assertUnauthorized();
        $this->assertSame($wrong->json(), $inactive->json());
        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited_per_email_and_ip(): void
    {
        $user = $this->staff('owner');

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'salah'])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', ['email' => strtoupper($user->email), 'password' => self::PASSWORD])
            ->assertTooManyRequests();
    }

    public function test_login_rejects_array_email_without_server_error(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => ['a@example.com'], 'password' => 'x'])
            ->assertUnprocessable();
    }

    public function test_permission_middleware_guards_api_on_the_server(): void
    {
        // POST: rute GET yang didaftarkan di test tertangkap catch-all SPA di routes/web.php lebih dulu.
        Route::middleware(['web', 'auth:sanctum', 'permission:users.manage'])
            ->post('/api/v1/test-admin', fn () => response()->json(['ok' => true]));

        $this->postJson('/api/v1/test-admin')->assertUnauthorized();

        $this->actingAs($this->staff('owner'))->postJson('/api/v1/test-admin')->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson('/api/v1/test-admin')->assertForbidden();
        $this->actingAs($this->staff('super-admin'))->postJson('/api/v1/test-admin')->assertOk();
    }

    public function test_admin_pages_do_not_load_storefront_bootstrap(): void
    {
        foreach (['/login', '/lupa-kata-sandi', '/admin', '/admin/forbidden'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('bootstrap.min.css');
        }

        $this->get('/')->assertOk()->assertSee('bootstrap.min.css');
    }

    public function test_create_super_admin_command_rejects_weak_password(): void
    {
        $this->artisan('admin:create-super-admin', ['email' => 'owner@example.com', 'name' => 'Akun Uji'])
            ->expectsQuestion('Password (minimal 12 karakter, huruf besar, huruf kecil, angka)', 'lemah')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'owner@example.com']);
    }

    public function test_create_super_admin_command_creates_account_and_audit_entry(): void
    {
        $this->artisan('admin:create-super-admin', ['email' => 'root@example.com', 'name' => 'Akun Uji'])
            ->expectsQuestion('Password (minimal 12 karakter, huruf besar, huruf kecil, angka)', self::PASSWORD)
            ->assertSuccessful();

        $user = User::where('email', 'root@example.com')->firstOrFail();

        $this->assertTrue($user->hasRole('super-admin'));
        $this->assertSame('user.created', AuditLog::where('auditable_id', $user->id)->value('action'));
        $this->assertStringNotContainsString(self::PASSWORD, (string) json_encode(AuditLog::first()?->changes));
    }
}
