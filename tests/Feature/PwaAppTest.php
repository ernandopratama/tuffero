<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-144: PWA toko dan admin, saklar terpisah, hanya pengguna yang sudah masuk. */
class PwaAppTest extends TestCase
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

    private function turnOn(string $key): void
    {
        Setting::where('key', $key)->update(['value' => '1']);
    }

    public function test_both_apps_are_off_by_default_and_manifests_are_missing(): void
    {
        $this->assertSame('0', Setting::where('key', 'pwa.store_enabled')->value('value'));
        $this->assertSame('0', Setting::where('key', 'pwa.admin_enabled')->value('value'));

        $this->get('/manifest.webmanifest')->assertNotFound();
        $this->get('/admin/manifest.webmanifest')->assertNotFound();
    }

    public function test_each_switch_only_enables_its_own_manifest(): void
    {
        $this->turnOn('pwa.store_enabled');

        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('scope', '/')
            ->assertJsonPath('display', 'standalone');
        $this->get('/admin/manifest.webmanifest')->assertNotFound();

        $this->turnOn('pwa.admin_enabled');

        $this->get('/admin/manifest.webmanifest')->assertOk()->assertJsonPath('scope', '/admin/')->assertJsonPath('id', '/admin/');
    }

    public function test_store_manifest_link_is_only_for_signed_in_customers(): void
    {
        $this->turnOn('pwa.store_enabled');

        $this->get('/')->assertOk()->assertDontSee('rel="manifest"', false);
        $this->actingAs($this->staff('store-admin'))->get('/')->assertOk()->assertDontSee('rel="manifest"', false);

        $customer = User::factory()->create();
        $this->actingAs($customer)->get('/')->assertOk()->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false);
        $this->actingAs($customer)->get('/akun')->assertOk()->assertSee('x-data="installApp"', false);
    }

    public function test_store_install_card_is_hidden_when_switch_is_off(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)->get('/akun')->assertOk()
            ->assertDontSee('rel="manifest"', false)
            ->assertDontSee('x-data="installApp"', false);
    }

    public function test_admin_manifest_link_is_only_for_signed_in_staff(): void
    {
        $this->turnOn('pwa.admin_enabled');

        $this->get('/login')->assertOk()->assertDontSee('rel="manifest"', false);
        $this->actingAs(User::factory()->create())->get('/admin')->assertOk()->assertDontSee('rel="manifest"', false);
        $this->actingAs($this->staff('warehouse'))->get('/admin')->assertOk()->assertSee('<link rel="manifest" href="/admin/manifest.webmanifest">', false);
    }

    public function test_only_super_admin_can_change_the_switches_and_it_is_audited(): void
    {
        foreach (['store-admin', 'owner'] as $role) {
            $this->actingAs($this->staff($role))
                ->patchJson('/api/v1/admin/settings/pwa.store_enabled', ['value' => 1])
                ->assertForbidden();
        }

        $this->actingAs($this->staff('store-admin'))->getJson('/api/v1/admin/settings')->assertOk()
            ->assertJsonMissing(['key' => 'pwa.store_enabled']);

        $super = $this->staff('super-admin');
        $this->actingAs($super)->patchJson('/api/v1/admin/settings/pwa.admin_enabled', ['value' => 2])->assertUnprocessable();
        $this->actingAs($super)->patchJson('/api/v1/admin/settings/pwa.admin_enabled', ['value' => 1])->assertOk();

        $this->assertSame('1', Setting::where('key', 'pwa.admin_enabled')->value('value'));
        $this->assertTrue(AuditLog::where('action', 'setting.updated')->where('changes->key', 'pwa.admin_enabled')->exists());
    }
}
