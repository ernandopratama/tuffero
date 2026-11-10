<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voucher;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

/**
 * Lonceng notifikasi dashboard: staf yang ditandai dengan @ di catatan internal menerima notifikasi miliknya sendiri.
 */
class AdminNotificationTest extends TestCase
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

    private function mention(User $author, User $target, string $body = 'tolong cek ya'): string
    {
        $voucher = Voucher::create(['code' => 'UJI'.random_int(100, 999), 'type' => 'fixed', 'value' => 10000, 'is_active' => true]);
        $this->actingAs($author)
            ->postJson("/api/v1/admin/vouchers/{$voucher->id}/comments", ['body' => "@{$target->name} {$body}", 'mentions' => [$target->id]])
            ->assertCreated();

        return $voucher->code;
    }

    public function test_tagged_staff_gets_a_notification_and_can_mark_it_read(): void
    {
        $owner = $this->staff('owner');
        $marketing = $this->staff('marketing');
        $code = $this->mention($owner, $marketing);

        $this->actingAs($marketing);
        $response = $this->getJson('/api/v1/admin/notifications')->assertOk()->assertJsonPath('data.unread_count', 1);
        $response->assertJsonPath('data.items.0.by', $owner->name)
            ->assertJsonPath('data.items.0.kind', 'voucher')
            ->assertJsonPath('data.items.0.label', $code)
            ->assertJsonPath('data.items.0.read_at', null);

        $id = $response->json('data.items.0.id');
        $this->postJson("/api/v1/admin/notifications/{$id}/read")->assertOk();
        $this->getJson('/api/v1/admin/notifications')->assertJsonPath('data.unread_count', 0);
    }

    public function test_notifications_belong_to_their_owner_only(): void
    {
        $owner = $this->staff('owner');
        $marketing = $this->staff('marketing');
        $other = $this->staff('store-admin');
        $this->mention($owner, $marketing);
        $id = $marketing->notifications()->firstOrFail()->id;

        $this->actingAs($other);
        $this->getJson('/api/v1/admin/notifications')->assertOk()->assertJsonPath('data.unread_count', 0)->assertJsonCount(0, 'data.items');
        $this->postJson("/api/v1/admin/notifications/{$id}/read")->assertNotFound();
        $this->assertNull($marketing->notifications()->firstOrFail()->read_at);
    }

    public function test_read_all_marks_every_unread_notification(): void
    {
        $owner = $this->staff('owner');
        $marketing = $this->staff('marketing');
        $this->mention($owner, $marketing);
        $this->mention($owner, $marketing);

        $this->actingAs($marketing)->postJson('/api/v1/admin/notifications/read')->assertOk();
        $this->getJson('/api/v1/admin/notifications')->assertJsonPath('data.unread_count', 0)->assertJsonCount(2, 'data.items');
    }

    public function test_customers_and_guests_cannot_use_staff_notifications(): void
    {
        $this->getJson('/api/v1/admin/notifications')->assertUnauthorized();

        $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/notifications')->assertForbidden();
    }

    public function test_comment_without_mentions_notifies_nobody(): void
    {
        $owner = $this->staff('owner');
        $voucher = Voucher::create(['code' => 'SEPI1', 'type' => 'fixed', 'value' => 10000, 'is_active' => true]);

        $this->actingAs($owner)->postJson("/api/v1/admin/vouchers/{$voucher->id}/comments", ['body' => 'tanpa tag'])->assertCreated();

        $this->assertSame(0, DatabaseNotification::count());
    }
}
