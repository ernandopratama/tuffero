<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\StoreReview;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreReviewTest extends TestCase
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

    /** @return array<string, mixed> */
    private function body(array $overrides = []): array
    {
        return ['author_name' => 'Budi', 'rating' => 5, 'body' => 'Nastarnya enak.', 'source' => 'google', ...$overrides];
    }

    public function test_seeded_google_reviews_are_public_with_rating_and_count_from_settings(): void
    {
        $data = $this->getJson('/api/v1/reviews')->assertOk()->json('data');

        $this->assertSame(4.6, $data['rating']);
        $this->assertSame(28, $data['count']);
        $this->assertCount(5, $data['reviews']);
    }

    public function test_store_admin_creates_updates_hides_and_deletes_reviews_with_audit(): void
    {
        $admin = $this->staff('store-admin');

        $id = $this->actingAs($admin)->postJson('/api/v1/admin/reviews', $this->body())->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/admin/reviews/{$id}", $this->body(['is_published' => false]))->assertOk();
        $this->assertNotContains('Budi', collect($this->getJson('/api/v1/reviews')->json('data.reviews'))->pluck('author_name')->all());

        $this->deleteJson("/api/v1/admin/reviews/{$id}")->assertOk();
        $this->assertDatabaseMissing('store_reviews', ['id' => $id]);
        $this->assertEqualsCanonicalizing(['review.created', 'review.updated', 'review.deleted'], AuditLog::pluck('action')->filter(fn ($a) => str_starts_with($a, 'review.'))->all());
    }

    public function test_super_admin_can_manage_reviews(): void
    {
        $this->actingAs($this->staff('super-admin'))->postJson('/api/v1/admin/reviews', $this->body())->assertCreated();
    }

    /** @return array<string, array{string}> */
    public static function otherRolesProvider(): array
    {
        return ['pemilik' => ['owner'], 'cs' => ['customer-service'], 'gudang' => ['warehouse'], 'marketing' => ['marketing']];
    }

    #[DataProvider('otherRolesProvider')]
    public function test_other_roles_cannot_manage_reviews(string $role): void
    {
        $this->actingAs($this->staff($role));

        $this->getJson('/api/v1/admin/reviews')->assertForbidden();
        $this->postJson('/api/v1/admin/reviews', $this->body())->assertForbidden();
    }

    public function test_review_validation(): void
    {
        $this->actingAs($this->staff('store-admin'));

        $this->postJson('/api/v1/admin/reviews', $this->body(['author_name' => '']))->assertUnprocessable();
        $this->postJson('/api/v1/admin/reviews', $this->body(['rating' => 6]))->assertUnprocessable();
        $this->postJson('/api/v1/admin/reviews', $this->body(['body' => str_repeat('x', 1001)]))->assertUnprocessable();
        $this->postJson('/api/v1/admin/reviews', $this->body(['source' => 'tidak-dikenal']))->assertUnprocessable();
        $this->postJson('/api/v1/admin/reviews', $this->body(['reviewed_on' => now()->addDay()->toDateString()]))->assertUnprocessable();
        $this->postJson('/api/v1/admin/reviews', $this->body(['rating' => null]))->assertCreated();
        $this->assertSame(6, StoreReview::count());
    }

    public function test_review_text_is_stored_and_returned_verbatim_for_the_client_to_escape(): void
    {
        $this->actingAs($this->staff('store-admin'))->postJson('/api/v1/admin/reviews', $this->body(['body' => '<script>alert(1)</script>']))->assertCreated();

        $this->assertContains('<script>alert(1)</script>', collect($this->getJson('/api/v1/reviews')->json('data.reviews'))->pluck('body')->all());
    }

    public function test_store_admin_manages_rating_and_count_settings_but_not_checkout_ones(): void
    {
        $this->actingAs($this->staff('store-admin'));

        $this->patchJson('/api/v1/admin/settings/reviews.rating', ['value' => 4.7])->assertOk();
        $this->patchJson('/api/v1/admin/settings/reviews.rating', ['value' => 6])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/settings/reviews.count', ['value' => 30])->assertOk();
        $this->patchJson('/api/v1/admin/settings/checkout.reservation_minutes', ['value' => 30])->assertForbidden();
        $this->assertSame(4.7, $this->getJson('/api/v1/reviews')->json('data.rating'));
    }

    public function test_review_list_filters_by_search_rating_and_status(): void
    {
        $this->actingAs($this->staff('store-admin'));
        $this->postJson('/api/v1/admin/reviews', $this->body(['author_name' => 'Zulkifli', 'rating' => 2, 'is_published' => false]))->assertCreated();

        $authors = fn (string $query) => collect($this->getJson("/api/v1/admin/reviews?{$query}")->assertOk()->json('data.items'))->pluck('author_name')->all();

        $this->assertSame(['Zulkifli'], $authors('search=Zulk'));
        $this->assertSame(['Zulkifli'], $authors('rating=2'));
        $this->assertSame(['Zulkifli'], $authors('is_published=0'));
    }
}
