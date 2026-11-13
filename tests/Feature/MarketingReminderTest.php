<?php

namespace Tests\Feature;

use App\Models\MarketingDraft;
use App\Models\User;
use App\Services\MarketingReminderService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Pengingat tim marketing (D-062, D-079): lonceng, Ringkasan, perintah harian, dan ringkasan hanya-baca untuk Hermes.
 */
class MarketingReminderTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'hermes-test-token-hermes-test-token-1234';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['services.hermes.token' => self::TOKEN]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    private function draft(string $status, array $attributes = []): MarketingDraft
    {
        $draft = new MarketingDraft(['type' => 'caption', 'title' => 'Caption Nastar', 'body' => 'Isi rahasia draf']);
        $draft->forceFill(['status' => $status, 'submitted_at' => now(), ...$attributes])->save();

        return $draft;
    }

    private function notifications(User $user): array
    {
        return DatabaseNotification::where('notifiable_id', $user->id)->get()->pluck('data')->all();
    }

    public function test_submitting_notifies_the_owner_and_deciding_notifies_marketing(): void
    {
        $owner = $this->staff('owner');
        $marketing = $this->staff('marketing');
        $otherMarketing = $this->staff('marketing');
        $draft = $this->draft('draft');

        $this->actingAs($marketing)->postJson("/api/v1/admin/marketing-drafts/{$draft->id}/move", ['action' => 'submit'])->assertOk();
        $this->assertSame('draft_submitted', $this->notifications($owner)[0]['event']);
        $this->assertSame('draft', $this->notifications($owner)[0]['kind']);
        $this->assertSame([], $this->notifications($marketing));

        $this->actingAs($owner)->postJson("/api/v1/admin/marketing-drafts/{$draft->id}/decide", ['decision' => 'reject', 'note' => 'Terlalu panjang'])->assertOk();
        $this->assertSame('draft_decided', $this->notifications($otherMarketing)[0]['event']);
        $this->assertStringContainsString('ditolak', $this->notifications($otherMarketing)[0]['title']);
        $this->assertSame('Terlalu panjang', $this->notifications($otherMarketing)[0]['excerpt']);
        $this->assertCount(1, $this->notifications($owner));
    }

    public function test_other_roles_get_no_draft_notifications(): void
    {
        $others = collect(['store-admin', 'customer-service', 'warehouse'])->map(fn ($role) => $this->staff($role));
        $draft = $this->draft('draft');

        $this->actingAs($this->staff('marketing'))->postJson("/api/v1/admin/marketing-drafts/{$draft->id}/move", ['action' => 'submit'])->assertOk();

        foreach ($others as $user) {
            $this->assertSame([], $this->notifications($user));
        }
    }

    public function test_dashboard_attention_per_role(): void
    {
        $this->draft('in_review');
        $this->draft('rejected');
        $this->draft('approved', ['scheduled_on' => now('Asia/Jakarta')->addDay()->toDateString()]);

        $keys = fn (string $role) => collect($this->actingAs($this->staff($role))->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.attention'))->pluck('key')->all();

        $this->assertContains('draft_review', $keys('owner'));
        $this->assertNotContains('draft_rejected', $keys('owner'));

        $marketing = $keys('marketing');
        $this->assertContains('draft_rejected', $marketing);
        $this->assertContains('draft_due', $marketing);
        $this->assertNotContains('draft_review', $marketing);

        $this->assertNotContains('draft_review', $keys('warehouse'));
        $this->assertNotContains('draft_due', $keys('customer-service'));
    }

    public function test_due_counts_follow_the_store_calendar_and_skip_posted_or_unscheduled(): void
    {
        // 07:00 WIB 15 Nov = 00:00 UTC 15 Nov; besok menurut toko = 16 Nov.
        $now = CarbonImmutable::parse('2026-11-15 00:00:00', 'UTC');
        $this->draft('approved', ['title' => 'Besok', 'scheduled_on' => '2026-11-16']);
        $this->draft('approved', ['title' => 'Lewat', 'scheduled_on' => '2026-11-12']);
        $this->draft('approved', ['title' => 'Lusa', 'scheduled_on' => '2026-11-17']);
        $this->draft('approved', ['title' => 'Tanpa jadwal']);
        $this->draft('posted', ['title' => 'Sudah', 'scheduled_on' => '2026-11-16']);
        $this->draft('in_review', ['title' => 'Draf lain', 'scheduled_on' => '2026-11-16']);

        $summary = app(MarketingReminderService::class)->summary($now);

        $this->assertSame(2, $summary['due']);
        $this->assertSame(['Lewat', 'Besok'], array_column($summary['due_items'], 'title'));
        $this->assertTrue($summary['due_items'][0]['overdue']);
        $this->assertFalse($summary['due_items'][1]['overdue']);
        $this->assertSame('2026-11-15', $summary['today']);
    }

    public function test_daily_command_notifies_once_a_day(): void
    {
        $owner = $this->staff('owner');
        $marketing = $this->staff('marketing');
        $this->draft('in_review', ['submitted_at' => now()->subDays(3)]);
        $this->draft('approved', ['scheduled_on' => now('Asia/Jakarta')->toDateString()]);

        Artisan::call('marketing:remind');
        Artisan::call('marketing:remind');

        $this->assertSame(['draft_waiting'], array_column($this->notifications($owner), 'event'));
        $this->assertSame(['draft_due'], array_column($this->notifications($marketing), 'event'));
    }

    public function test_daily_command_is_silent_when_nothing_is_due(): void
    {
        $owner = $this->staff('owner');
        $marketing = $this->staff('marketing');
        $this->draft('in_review', ['submitted_at' => now()]);
        $this->draft('approved', ['scheduled_on' => now('Asia/Jakarta')->addDays(5)->toDateString()]);

        Artisan::call('marketing:remind');

        $this->assertSame([], $this->notifications($owner));
        $this->assertSame([], $this->notifications($marketing));
    }

    public function test_hermes_summary_needs_the_token_and_leaks_no_draft_body(): void
    {
        $this->draft('approved', ['title' => 'Caption Natal', 'scheduled_on' => now('Asia/Jakarta')->toDateString()]);

        $this->getJson('/api/v1/integrations/hermes/reminders')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer salah-salah-salah-salah-salah-12345')->getJson('/api/v1/integrations/hermes/reminders')->assertUnauthorized();

        $response = $this->withHeader('Authorization', 'Bearer '.self::TOKEN)->getJson('/api/v1/integrations/hermes/reminders')->assertOk();
        $response->assertJsonPath('data.due', 1)->assertJsonPath('data.due_items.0.title', 'Caption Natal');
        $this->assertStringNotContainsString('Isi rahasia draf', $response->getContent());
    }

    public function test_hermes_summary_is_off_without_a_token_and_blocks_staff_sessions(): void
    {
        $this->actingAs($this->staff('super-admin'))->getJson('/api/v1/integrations/hermes/reminders')->assertUnauthorized();

        config(['services.hermes.token' => null]);
        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)->getJson('/api/v1/integrations/hermes/reminders')->assertNotFound();
    }

    public function test_muted_event_is_not_delivered(): void
    {
        $owner = $this->staff('owner');
        $owner->forceFill(['notification_muted' => ['draft_submitted']])->save();
        $draft = $this->draft('draft');

        $this->actingAs($this->staff('marketing'))->postJson("/api/v1/admin/marketing-drafts/{$draft->id}/move", ['action' => 'submit'])->assertOk();

        $this->assertSame([], $this->notifications($owner));
    }
}
