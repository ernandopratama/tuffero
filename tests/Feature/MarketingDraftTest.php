<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MarketingDraft;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingDraftTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/marketing-drafts';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    private function staff(string $role, bool $mfa = true): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => $mfa ? now() : null]);
        $user->assignRole($role);

        return $user;
    }

    /** @param array<string, mixed> $override */
    private function payload(array $override = []): array
    {
        return ['type' => 'video_script', 'platform' => 'instagram', 'title' => 'Nastar Lebaran', 'body' => 'Isi rencana <b>uji</b>', ...$override];
    }

    private function draft(string $status = 'draft'): MarketingDraft
    {
        $draft = new MarketingDraft($this->payload());
        $draft->forceFill(['status' => $status, 'created_by_user_id' => $this->staff('marketing')->id])->save();

        return $draft;
    }

    public function test_the_list_comes_newest_created_first_and_exposes_created_at(): void
    {
        $this->travelTo(now()->subDays(2));
        $old = $this->draft();
        $this->travelBack();
        $recent = $this->draft();
        $recent->forceFill(['scheduled_on' => now()->addDays(5)])->save();
        $old->forceFill(['scheduled_on' => now()->addDay()])->save();

        $items = $this->actingAs($this->staff('marketing'))->getJson(self::URL)->assertOk()->json('data.items');

        $this->assertSame([$recent->public_id, $old->public_id], array_column($items, 'id'), 'Terbaru dibuat dulu, bukan menurut jadwal');
        $this->assertNotNull($items[0]['created_at']);
    }

    public function test_every_role_can_view_but_only_marketing_and_super_admin_can_manage(): void
    {
        foreach (['super-admin', 'owner', 'store-admin', 'customer-service', 'warehouse', 'marketing'] as $role) {
            $this->actingAs($this->staff($role))->getJson(self::URL)->assertOk();
        }

        foreach (['owner', 'store-admin', 'customer-service', 'warehouse'] as $role) {
            $this->actingAs($this->staff($role))->postJson(self::URL, $this->payload())->assertForbidden();
        }

        $this->actingAs($this->staff('marketing'))->postJson(self::URL, $this->payload())->assertCreated()->assertJsonPath('data.status', 'draft');
        $this->actingAs($this->staff('super-admin'))->postJson(self::URL, $this->payload())->assertCreated();
        $this->assertSame(2, AuditLog::where('action', 'marketing_draft.created')->count());
    }

    public function test_guest_is_refused_and_writes_need_mfa(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->actingAs($this->staff('marketing', mfa: false))->postJson(self::URL, $this->payload())->assertForbidden();
    }

    public function test_full_flow_submit_approve_post(): void
    {
        $marketing = $this->staff('marketing');
        $id = $this->actingAs($marketing)->postJson(self::URL, $this->payload())->json('data.id');

        $this->actingAs($marketing)->postJson(self::URL."/{$id}/move", ['action' => 'submit'])->assertOk()->assertJsonPath('data.status', 'in_review');
        $this->actingAs($this->staff('owner'))->postJson(self::URL."/{$id}/decide", ['decision' => 'approve', 'note' => 'Bagus'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->actingAs($marketing)->postJson(self::URL."/{$id}/move", ['action' => 'post'])->assertOk()->assertJsonPath('data.status', 'posted');

        $this->assertNotNull(MarketingDraft::where('public_id', $id)->first()->posted_at);
        foreach (['submit', 'approved', 'post'] as $suffix) {
            $this->assertDatabaseHas('audit_logs', ['action' => 'marketing_draft.'.$suffix]);
        }
    }

    public function test_marketing_cannot_approve_and_owner_cannot_submit(): void
    {
        $draft = $this->draft('in_review');

        $this->actingAs($this->staff('marketing'))->postJson(self::URL."/{$draft->public_id}/decide", ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($this->staff('owner'))->postJson(self::URL."/{$this->draft()->public_id}/move", ['action' => 'submit'])->assertForbidden();
        $this->assertSame('in_review', $draft->fresh()->status);
    }

    public function test_rejection_needs_a_reason_and_returns_the_draft_for_edit(): void
    {
        $draft = $this->draft('in_review');
        $owner = $this->staff('owner');

        $this->actingAs($owner)->postJson(self::URL."/{$draft->public_id}/decide", ['decision' => 'reject'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->actingAs($owner)->postJson(self::URL."/{$draft->public_id}/decide", ['decision' => 'reject', 'note' => 'Terlalu panjang'])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->actingAs($this->staff('marketing'))->patchJson(self::URL."/{$draft->public_id}", $this->payload(['title' => 'Revisi']))->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.review_note', null);
    }

    public function test_caption_and_calendar_item_can_no_longer_be_created(): void
    {
        $marketing = $this->staff('marketing');

        foreach (['caption', 'calendar_item'] as $type) {
            $this->actingAs($marketing)->postJson(self::URL, $this->payload(['type' => $type]))->assertUnprocessable()->assertJsonValidationErrors('type');
        }
    }

    public function test_a_trend_report_cannot_be_marked_as_posted(): void
    {
        $draft = new MarketingDraft(['type' => 'trend_report', 'title' => 'Riset', 'body' => 'x', 'sources' => 's', 'source_period' => 'p']);
        $draft->forceFill(['status' => 'approved'])->save();

        $this->actingAs($this->staff('marketing'))->postJson(self::URL."/{$draft->public_id}/move", ['action' => 'post'])->assertUnprocessable();
        $this->assertSame('approved', $draft->fresh()->status);
    }

    public function test_invalid_transitions_are_refused(): void
    {
        $marketing = $this->staff('marketing');
        $owner = $this->staff('owner');

        // Tidak bisa diposting sebelum disetujui, tidak bisa diputuskan sebelum diajukan, tidak bisa disunting saat ditinjau atau disetujui.
        $draft = $this->draft('draft');
        $this->actingAs($marketing)->postJson(self::URL."/{$draft->public_id}/move", ['action' => 'post'])->assertUnprocessable();
        $this->actingAs($owner)->postJson(self::URL."/{$draft->public_id}/decide", ['decision' => 'approve'])->assertUnprocessable();

        foreach (['in_review', 'approved', 'posted'] as $status) {
            $locked = $this->draft($status);
            $this->actingAs($marketing)->patchJson(self::URL."/{$locked->public_id}", $this->payload(['title' => 'Ubah']))->assertUnprocessable();
            $this->actingAs($marketing)->deleteJson(self::URL."/{$locked->public_id}")->assertUnprocessable();
            $this->assertSame('Nastar Lebaran', $locked->fresh()->title);
        }

        $this->actingAs($marketing)->postJson(self::URL."/{$draft->public_id}/move", ['action' => 'approve'])->assertUnprocessable();
    }

    public function test_decision_is_final_for_a_second_request(): void
    {
        $draft = $this->draft('in_review');
        $owner = $this->staff('owner');

        $this->actingAs($owner)->postJson(self::URL."/{$draft->public_id}/decide", ['decision' => 'approve'])->assertOk();
        $this->actingAs($owner)->postJson(self::URL."/{$draft->public_id}/decide", ['decision' => 'reject', 'note' => 'Terlambat'])->assertUnprocessable();
        $this->assertSame('approved', $draft->fresh()->status);
    }

    public function test_trend_report_needs_sources_and_period(): void
    {
        $marketing = $this->staff('marketing');

        $this->actingAs($marketing)->postJson(self::URL, $this->payload(['type' => 'trend_report']))->assertUnprocessable()->assertJsonValidationErrors(['sources', 'source_period']);
        $this->actingAs($marketing)->postJson(self::URL, $this->payload(['type' => 'trend_report', 'sources' => 'TikTok Creative Center', 'source_period' => '1 sampai 7 November 2026']))->assertCreated();
        $this->actingAs($marketing)->postJson(self::URL, $this->payload(['platform' => 'facebook']))->assertUnprocessable()->assertJsonValidationErrors('platform');
    }

    public function test_status_cannot_be_set_through_mass_assignment(): void
    {
        $id = $this->actingAs($this->staff('marketing'))->postJson(self::URL, $this->payload(['status' => 'approved', 'created_by_user_id' => 999, 'posted_at' => now()->toDateTimeString()]))->assertCreated()->json('data.id');

        $draft = MarketingDraft::where('public_id', $id)->first();
        $this->assertSame('draft', $draft->status);
        $this->assertNull($draft->posted_at);
        $this->assertNotSame(999, $draft->created_by_user_id);
    }

    public function test_markdown_marks_are_stripped_on_create_and_update(): void
    {
        $marketing = $this->staff('marketing');
        $id = $this->actingAs($marketing)->postJson(self::URL, $this->payload(['title' => '**Judul**', 'body' => "## Tren\n**Tebal** dan __lain__\n* satu\n* dua\n[Tokopedia](https://tokopedia.com/x)"]))->assertCreated()->json('data.id');

        $draft = MarketingDraft::where('public_id', $id)->first();
        $this->assertSame('Judul', $draft->title);
        $this->assertSame("Tren\nTebal dan lain\n- satu\n- dua\nTokopedia (https://tokopedia.com/x)", $draft->body);

        $this->actingAs($marketing)->patchJson(self::URL.'/'.$id, $this->payload(['body' => '**Baru**']))->assertOk();
        $this->assertSame('Baru', $draft->fresh()->body);
    }

    public function test_frequency_defaults_and_week_progress(): void
    {
        $marketing = $this->staff('marketing');
        $this->actingAs($marketing)->postJson(self::URL, $this->payload(['scheduled_on' => now()->toDateString()]))->assertCreated();

        $this->actingAs($marketing)->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.frequency', ['videos_per_week' => 2])
            ->assertJsonPath('data.week', ['videos' => 1]);
    }

    public function test_only_super_admin_changes_the_frequency_setting(): void
    {
        $setting = Setting::where('key', 'marketing.videos_per_week')->firstOrFail();

        $this->actingAs($this->staff('store-admin'))->patchJson("/api/v1/admin/settings/{$setting->key}", ['value' => 5])->assertForbidden();
        $this->actingAs($this->staff('marketing'))->patchJson("/api/v1/admin/settings/{$setting->key}", ['value' => 5])->assertForbidden();
        $this->actingAs($this->staff('super-admin'))->patchJson("/api/v1/admin/settings/{$setting->key}", ['value' => 5])->assertOk();
        $this->actingAs($this->staff('super-admin'))->patchJson("/api/v1/admin/settings/{$setting->key}", ['value' => 99])->assertUnprocessable();
    }

    public function test_delete_is_soft_and_audited(): void
    {
        $draft = $this->draft('draft');

        $this->actingAs($this->staff('marketing'))->deleteJson(self::URL."/{$draft->public_id}")->assertOk();

        $this->assertSoftDeleted('marketing_drafts', ['id' => $draft->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketing_draft.deleted']);
    }
}
