<?php

namespace Tests\Feature;

use App\Models\ReturnBlockRuleRequest;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Menyalakan blokir otomatis retur lewat pengajuan yang disetujui Pemilik (D-133). */
class ReturnBlockRuleTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'antifraud.return_block_enabled';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function staff(string ...$roles): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($roles);

        return $user;
    }

    private function enabled(): bool
    {
        return Setting::where('key', self::KEY)->value('value') === '1';
    }

    private function request(User $by, string $note = 'Syarat dan ketentuan sudah menyebut daftar blokir'): ReturnBlockRuleRequest
    {
        $this->actingAs($by)->postJson('/api/v1/admin/customer-blocks/rule/request', ['note' => $note])->assertCreated();

        return ReturnBlockRuleRequest::firstOrFail();
    }

    public function test_rule_is_off_by_default_and_visible_to_viewers(): void
    {
        $this->actingAs($this->staff('customer-service'))->getJson('/api/v1/admin/customer-blocks/rule')
            ->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.pending', null);
    }

    public function test_store_admin_requests_and_owner_approves(): void
    {
        $storeAdmin = $this->staff('store-admin');
        $owner = $this->staff('owner');
        $ruleRequest = $this->request($storeAdmin);

        $this->assertFalse($this->enabled());
        $this->assertSame(1, $owner->notifications()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer_block_rule.requested', 'actor_user_id' => $storeAdmin->id]);

        $this->actingAs($owner)->postJson("/api/v1/admin/customer-blocks/rule/requests/{$ruleRequest->id}/decide", ['decision' => 'approve', 'note' => 'Setuju'])
            ->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.pending', null)->assertJsonPath('data.last_decision.status', 'approved');

        $this->assertTrue($this->enabled());
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer_block_rule.approved', 'actor_user_id' => $owner->id]);
        $this->assertSame(1, $storeAdmin->notifications()->count());
    }

    public function test_super_admin_can_request_but_cannot_approve(): void
    {
        $superAdmin = $this->staff('super-admin');
        $ruleRequest = $this->request($superAdmin);

        // Super Admin memegang semua izin, tetapi hanya peran Pemilik yang memutuskan.
        $this->actingAs($superAdmin)->postJson("/api/v1/admin/customer-blocks/rule/requests/{$ruleRequest->id}/decide", ['decision' => 'approve'])->assertForbidden();
        $this->assertFalse($this->enabled());
        $this->assertSame('pending', $ruleRequest->fresh()->status);
    }

    public function test_store_admin_cannot_approve_and_owner_cannot_request(): void
    {
        $storeAdmin = $this->staff('store-admin');
        $owner = $this->staff('owner');
        $ruleRequest = $this->request($storeAdmin);

        $this->actingAs($storeAdmin)->postJson("/api/v1/admin/customer-blocks/rule/requests/{$ruleRequest->id}/decide", ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($owner)->postJson('/api/v1/admin/customer-blocks/rule/request', ['note' => 'Pemilik mengajukan sendiri'])->assertForbidden();
        $this->actingAs($this->staff('customer-service'))->postJson('/api/v1/admin/customer-blocks/rule/request', ['note' => 'CS mengajukan'])->assertForbidden();
        $this->assertFalse($this->enabled());
    }

    public function test_requester_cannot_approve_their_own_request(): void
    {
        $both = $this->staff('store-admin', 'owner');
        $ruleRequest = $this->request($both);

        $this->actingAs($both)->postJson("/api/v1/admin/customer-blocks/rule/requests/{$ruleRequest->id}/decide", ['decision' => 'approve'])->assertJsonValidationErrors('rule');
        $this->assertFalse($this->enabled());
    }

    public function test_only_one_pending_request_and_notes_are_required(): void
    {
        $storeAdmin = $this->staff('store-admin');
        $this->actingAs($storeAdmin)->postJson('/api/v1/admin/customer-blocks/rule/request', [])->assertJsonValidationErrors('note');
        $this->actingAs($storeAdmin)->postJson('/api/v1/admin/customer-blocks/rule/request', ['note' => 'abc'])->assertJsonValidationErrors('note');

        $this->request($storeAdmin);
        $this->actingAs($storeAdmin)->postJson('/api/v1/admin/customer-blocks/rule/request', ['note' => 'Ajukan lagi sekarang'])->assertJsonValidationErrors('rule');
        $this->assertSame(1, ReturnBlockRuleRequest::count());
    }

    public function test_owner_rejection_needs_a_reason_and_keeps_the_rule_off(): void
    {
        $storeAdmin = $this->staff('store-admin');
        $owner = $this->staff('owner');
        $ruleRequest = $this->request($storeAdmin);

        $this->actingAs($owner)->postJson("/api/v1/admin/customer-blocks/rule/requests/{$ruleRequest->id}/decide", ['decision' => 'reject'])->assertJsonValidationErrors('note');
        $this->actingAs($owner)->postJson("/api/v1/admin/customer-blocks/rule/requests/{$ruleRequest->id}/decide", ['decision' => 'reject', 'note' => 'Tunggu S&K final'])
            ->assertOk()->assertJsonPath('data.last_decision.status', 'rejected');

        $this->assertFalse($this->enabled());
        // Sudah diputuskan: tidak bisa diputuskan dua kali, dan pengajuan baru boleh dibuat.
        $this->actingAs($owner)->postJson("/api/v1/admin/customer-blocks/rule/requests/{$ruleRequest->id}/decide", ['decision' => 'approve'])->assertJsonValidationErrors('rule');
        $this->actingAs($storeAdmin)->postJson('/api/v1/admin/customer-blocks/rule/request', ['note' => 'Pengajuan kedua setelah S&K'])->assertCreated();
    }

    public function test_settings_cannot_switch_the_rule_on_but_can_switch_it_off(): void
    {
        $superAdmin = $this->staff('super-admin');

        $this->actingAs($superAdmin)->patchJson('/api/v1/admin/settings/'.self::KEY, ['value' => 1])->assertJsonValidationErrors('value');
        $this->assertFalse($this->enabled());

        Setting::where('key', self::KEY)->update(['value' => '1']);
        $this->actingAs($superAdmin)->patchJson('/api/v1/admin/settings/'.self::KEY, ['value' => 0])->assertOk();
        $this->assertFalse($this->enabled());
    }

    public function test_disable_needs_a_note_and_is_audited_and_can_be_requested_again(): void
    {
        $storeAdmin = $this->staff('store-admin');
        $owner = $this->staff('owner');
        $ruleRequest = $this->request($storeAdmin);
        $this->actingAs($owner)->postJson("/api/v1/admin/customer-blocks/rule/requests/{$ruleRequest->id}/decide", ['decision' => 'approve'])->assertOk();

        $this->actingAs($storeAdmin)->postJson('/api/v1/admin/customer-blocks/rule/disable', [])->assertJsonValidationErrors('note');
        $this->actingAs($storeAdmin)->postJson('/api/v1/admin/customer-blocks/rule/disable', ['note' => 'Terlalu banyak salah blokir'])->assertOk()->assertJsonPath('data.enabled', false);

        $this->assertFalse($this->enabled());
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer_block_rule.disabled', 'actor_user_id' => $storeAdmin->id]);
        $this->actingAs($storeAdmin)->postJson('/api/v1/admin/customer-blocks/rule/disable', ['note' => 'Sudah mati sebelumnya'])->assertJsonValidationErrors('rule');
        $this->actingAs($storeAdmin)->postJson('/api/v1/admin/customer-blocks/rule/request', ['note' => 'Nyalakan lagi setelah evaluasi'])->assertCreated();
    }

    public function test_writes_require_mfa_enrollment_outside_local(): void
    {
        $storeAdmin = User::factory()->create(['mfa_totp_confirmed_at' => null]);
        $storeAdmin->assignRole('store-admin');

        $this->actingAs($storeAdmin)->postJson('/api/v1/admin/customer-blocks/rule/request', ['note' => 'Tanpa MFA terdaftar'])->assertForbidden();
        $this->assertSame(0, ReturnBlockRuleRequest::count());
    }
}
