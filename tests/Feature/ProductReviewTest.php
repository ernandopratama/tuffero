<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ProductReviewTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->product = Product::create(['name' => 'Nastar', 'slug' => 'nastar', 'is_published' => true]);
        $variant = $this->product->variants()->create(['sku' => 'NAS-01', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500, 'is_active' => true]);
        InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->firstOrFail()->id, 'product_variant_id' => $variant->id, 'quantity' => 50]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function order(?User $user = null): Order
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 1])->assertOk();

        $ref = $this->withHeader('Idempotency-Key', 'key-'.str()->random(20))->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi Uji',
            'customer_email' => 'budi@example.com',
            'customer_phone' => '081234567890',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt',
            'service' => 'ez',
        ])->assertCreated()->json('data.public_ref');

        return Order::where('public_ref', $ref)->firstOrFail();
    }

    private function review(Order $order, array $body = []): TestResponse
    {
        return $this->withSession(["order_access.{$order->public_ref}" => true])
            ->postJson("/api/v1/orders/{$order->public_ref}/reviews", ['sku' => 'NAS-01', 'rating' => 5, 'body' => 'Enak sekali.', ...$body]);
    }

    public function test_buyer_reviews_a_completed_order_once_with_a_shortened_name(): void
    {
        $order = $this->order();
        $order->forceFill(['status' => Order::COMPLETED])->save();

        $this->review($order)->assertCreated();
        $this->review($order)->assertUnprocessable();

        $this->assertSame(1, ProductReview::count());
        $this->assertSame('Budi U.', ProductReview::first()->author_name);
        $this->assertSame($this->product->id, ProductReview::first()->product_id);

        $this->withSession(["order_access.{$order->public_ref}" => true])->getJson("/api/v1/orders/{$order->public_ref}")
            ->assertJsonPath('data.items.0.can_review', false)
            ->assertJsonPath('data.items.0.review.rating', 5);
    }

    public function test_review_is_refused_before_completion_and_without_order_access(): void
    {
        $order = $this->order();

        $this->review($order)->assertUnprocessable();

        $order->forceFill(['status' => Order::COMPLETED])->save();
        $this->flushSession();
        $this->postJson("/api/v1/orders/{$order->public_ref}/reviews", ['sku' => 'NAS-01', 'rating' => 5])->assertNotFound();
        $this->assertSame(0, ProductReview::count());
    }

    public function test_review_validation_and_unknown_sku(): void
    {
        $order = $this->order();
        $order->forceFill(['status' => Order::COMPLETED])->save();

        $this->review($order, ['rating' => 6])->assertUnprocessable();
        $this->review($order, ['rating' => 0])->assertUnprocessable();
        $this->review($order, ['body' => str_repeat('x', 1001)])->assertUnprocessable();
        $this->review($order, ['sku' => 'TIDAK-ADA'])->assertUnprocessable();
        $this->assertSame(0, ProductReview::count());
    }

    public function test_published_reviews_show_on_the_product_page_and_hidden_ones_do_not(): void
    {
        ProductReview::create(['product_id' => $this->product->id, 'author_name' => 'Sari P.', 'rating' => 4, 'body' => 'Renyah', 'is_published' => true]);
        ProductReview::create(['product_id' => $this->product->id, 'author_name' => 'Rahasia R.', 'rating' => 1, 'body' => 'Disembunyikan', 'is_published' => false]);

        $this->get('/produk/nas-01')->assertOk()->assertSee('Sari P.')->assertSee('Renyah')->assertDontSee('Rahasia R.');
    }

    public function test_only_super_admin_can_manage_but_every_role_can_view(): void
    {
        $review = ProductReview::create(['product_id' => $this->product->id, 'author_name' => 'Sari P.', 'rating' => 4]);
        $body = ['product_id' => $this->product->id, 'author_name' => 'Tamu', 'rating' => 5, 'body' => 'Bagus'];

        foreach (['owner', 'store-admin', 'customer-service', 'warehouse', 'marketing', 'super-admin'] as $role) {
            $this->actingAs($this->staff($role))->getJson('/api/v1/admin/product-reviews')->assertOk()->assertJsonPath('data.meta.total', 1);
        }

        foreach (['owner', 'store-admin', 'customer-service', 'warehouse', 'marketing'] as $role) {
            $this->actingAs($this->staff($role));
            $this->postJson('/api/v1/admin/product-reviews', $body)->assertForbidden();
            $this->patchJson("/api/v1/admin/product-reviews/{$review->id}", ['author_name' => 'X', 'rating' => 1])->assertForbidden();
            $this->deleteJson("/api/v1/admin/product-reviews/{$review->id}")->assertForbidden();
        }

        $this->assertSame(1, ProductReview::count());
        $this->assertSame(4, $review->fresh()->rating);
    }

    public function test_super_admin_creates_updates_hides_and_deletes_with_audit(): void
    {
        $this->actingAs($this->staff('super-admin'));
        $id = $this->postJson('/api/v1/admin/product-reviews', ['product_id' => $this->product->id, 'author_name' => 'Tamu', 'rating' => 5, 'body' => 'Bagus'])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/admin/product-reviews/{$id}", ['author_name' => 'Tamu', 'rating' => 3, 'is_published' => false])->assertOk()->assertJsonPath('data.rating', 3);
        $this->patchJson("/api/v1/admin/product-reviews/{$id}", ['product_id' => 999, 'author_name' => 'Tamu', 'rating' => 3])->assertUnprocessable();
        $this->postJson('/api/v1/admin/product-reviews', ['product_id' => $this->product->id, 'author_name' => 'Tamu', 'rating' => 9])->assertUnprocessable();

        $this->deleteJson("/api/v1/admin/product-reviews/{$id}")->assertOk();
        $this->assertDatabaseMissing('product_reviews', ['id' => $id]);
        $this->assertEqualsCanonicalizing(['product_review.created', 'product_review.updated', 'product_review.deleted'], AuditLog::pluck('action')->filter(fn ($a) => str_starts_with($a, 'product_review.'))->all());
    }

    public function test_guests_cannot_open_the_admin_list(): void
    {
        $this->getJson('/api/v1/admin/product-reviews')->assertUnauthorized();
    }
}
