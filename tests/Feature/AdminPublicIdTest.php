<?php

namespace Tests\Feature;

use App\Models\CustomerBlock;
use App\Models\FollowUp;
use App\Models\InventoryStock;
use App\Models\MarketingDraft;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderStatusService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** D-143 tahap 1-4: data di URL/API admin memakai ID publik; ID angka ditolak; izin tetap. */
class AdminPublicIdTest extends TestCase
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

    private function order(): Order
    {
        if (! Product::where('slug', 'nastar')->exists()) {
            $variant = Product::create(['name' => 'Nastar', 'slug' => 'nastar', 'is_published' => true])
                ->variants()->create(['sku' => 'NAS-01', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);
            InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->value('id'), 'product_variant_id' => $variant->id, 'quantity' => 10]);
        }

        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 1])->assertOk();
        $ref = $this->withHeader('Idempotency-Key', 'key-'.str()->random(20))->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi Uji', 'customer_email' => 'budi@example.com', 'customer_phone' => '081234567890',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt', 'service' => 'ez',
        ])->assertCreated()->json('data.public_ref');

        return app(OrderStatusService::class)->markPaid(Order::where('public_ref', $ref)->firstOrFail());
    }

    public function test_new_rows_get_a_lowercase_ulid_public_id(): void
    {
        $product = Product::create(['name' => 'Nastar', 'slug' => 'nastar-'.uniqid()]);
        $user = User::factory()->create();

        foreach ([$product->public_id, $user->public_id] as $id) {
            $this->assertTrue(Str::isUlid($id));
            $this->assertSame(strtolower($id), $id);
        }
        $this->assertNotSame($product->public_id, $user->public_id);
        $this->assertIsInt($product->id, 'ID angka tetap kunci internal.');
    }

    public function test_product_routes_use_public_id_and_reject_numeric_id(): void
    {
        $product = Product::create(['name' => 'Nastar', 'slug' => 'nastar-'.uniqid()]);
        $this->actingAs($this->staff('store-admin'));

        $this->getJson("/api/v1/admin/products/{$product->public_id}")->assertOk()->assertJsonPath('data.id', $product->public_id);
        $this->getJson("/api/v1/admin/products/{$product->id}")->assertNotFound();
        $this->getJson('/api/v1/admin/products/01jaaaaaaaaaaaaaaaaaaaaaaa')->assertNotFound();

        $listed = collect($this->getJson('/api/v1/admin/products')->assertOk()->json('data.items'))->pluck('id');
        $this->assertTrue($listed->every(fn ($id) => is_string($id) && Str::isUlid($id)));
    }

    public function test_customer_routes_use_public_id_and_keep_permissions(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($this->staff('owner'));
        $this->getJson("/api/v1/admin/customers/{$customer->public_id}")->assertOk()->assertJsonPath('data.id', $customer->public_id);
        $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertNotFound();

        // Izin tidak berubah: Gudang tetap ditolak walau memakai ID publik yang benar.
        $this->actingAs($this->staff('warehouse'));
        $this->getJson("/api/v1/admin/customers/{$customer->public_id}")->assertForbidden();

        // Akun staf tidak bisa dibuka sebagai pelanggan.
        $this->actingAs($this->staff('owner'));
        $this->getJson('/api/v1/admin/customers/'.$this->staff('marketing')->public_id)->assertNotFound();
    }

    public function test_order_routes_use_public_ref_and_reject_numeric_id(): void
    {
        $order = $this->order();
        $this->actingAs($this->staff('store-admin'));

        $this->getJson("/api/v1/admin/orders/{$order->public_ref}")->assertOk()->assertJsonPath('data.id', $order->public_ref);
        $this->getJson("/api/v1/admin/orders/{$order->id}")->assertNotFound();
        $this->assertSame($order->public_ref, $this->getJson('/api/v1/admin/orders')->json('data.items.0.id'));

        // Label resi tetap butuh shipments.manage.
        $this->actingAs($this->staff('owner'));
        $this->getJson("/api/v1/admin/orders/{$order->public_ref}/label")->assertForbidden();
    }

    public function test_pick_list_accepts_public_refs_only(): void
    {
        $order = $this->order();
        $this->actingAs($this->staff('warehouse'));

        $this->getJson('/api/v1/admin/fulfillment/pick-list?ids='.$order->public_ref)->assertOk()->assertJsonPath('data.requested', 1)->assertJsonPath('data.skipped', 0);
        $this->getJson('/api/v1/admin/fulfillment/pick-list?ids='.$order->id)->assertUnprocessable();
    }

    public function test_session_profile_and_staff_list_expose_public_ids(): void
    {
        $admin = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $admin->assignRole('super-admin');
        $this->actingAs($admin);

        $this->getJson('/api/v1/auth/user')->assertOk()->assertJsonPath('data.user.id', $admin->public_id);
        $ids = collect($this->getJson('/api/v1/admin/users')->assertOk()->json('data.items'))->pluck('id');
        $this->assertTrue($ids->contains($admin->public_id));
        $this->assertFalse($ids->contains($admin->id));
    }

    public function test_comment_mentions_use_public_ids(): void
    {
        $order = $this->order();
        $author = $this->staff('store-admin');
        $mentioned = $this->staff('customer-service');
        $this->actingAs($author);

        $this->postJson("/api/v1/admin/orders/{$order->public_ref}/comments", ['body' => '@'.$mentioned->name.' cek', 'mentions' => [$mentioned->id]])->assertUnprocessable();
        $this->postJson("/api/v1/admin/orders/{$order->public_ref}/comments", ['body' => '@'.$mentioned->name.' cek', 'mentions' => [$mentioned->public_id]])
            ->assertCreated()->assertJsonPath('data.mentions.0.id', $mentioned->public_id);
        $this->assertSame([$mentioned->id], $order->comments()->first()->mentions);
    }

    public function test_cancellation_and_follow_up_routes_use_public_id(): void
    {
        $order = $this->order();
        $this->withSession(["order_access.{$order->public_ref}" => true])
            ->postJson("/api/v1/orders/{$order->public_ref}/cancellation", ['reason' => 'Salah pesan', 'channel' => 'whatsapp'])->assertCreated();
        $cancellation = OrderCancellationRequest::latest('id')->firstOrFail();
        $this->assertTrue(Str::isUlid($cancellation->public_id));

        $cs = $this->staff('customer-service');
        $this->actingAs($cs);
        $listed = collect($this->getJson('/api/v1/admin/cancellations')->assertOk()->json('data.items'))->pluck('id');
        $this->assertTrue($listed->contains($cancellation->public_id));
        $this->assertSame($order->public_ref, $this->getJson('/api/v1/admin/cancellations')->json('data.items.0.order_id'));

        $this->getJson("/api/v1/admin/cancellations/{$cancellation->public_id}/follow-ups")->assertOk();
        $this->getJson("/api/v1/admin/cancellations/{$cancellation->id}/follow-ups")->assertNotFound();
        $this->postJson("/api/v1/admin/cancellations/{$cancellation->public_id}/validate")->assertOk();

        $followUp = $this->postJson("/api/v1/admin/cancellations/{$cancellation->public_id}/follow-ups", ['note' => 'Hubungi pembeli', 'due_on' => now()->addDay()->toDateString()])
            ->assertCreated()->json('data.id');
        $this->assertTrue(Str::isUlid($followUp));
        $this->postJson('/api/v1/admin/follow-ups/'.FollowUp::where('public_id', $followUp)->value('id').'/done')->assertNotFound();
        $this->postJson("/api/v1/admin/follow-ups/{$followUp}/done")->assertOk();

        // Izin tetap: Marketing tidak bisa membuka penanda pembatalan walau ID-nya benar.
        $this->actingAs($this->staff('marketing'))->getJson("/api/v1/admin/cancellations/{$cancellation->public_id}/follow-ups")->assertForbidden();
    }

    public function test_customer_block_routes_use_public_id(): void
    {
        $this->actingAs($this->staff('store-admin'));
        $id = $this->postJson('/api/v1/admin/customer-blocks', ['phone' => '081299990000', 'note' => 'Blokir manual uji'])->assertCreated()->json('data.id');
        $this->assertTrue(Str::isUlid($id));
        $numeric = CustomerBlock::where('public_id', $id)->value('id');

        $this->patchJson("/api/v1/admin/customer-blocks/{$numeric}", ['note' => 'Catatan baru'])->assertNotFound();
        $this->patchJson("/api/v1/admin/customer-blocks/{$id}", ['note' => 'Catatan baru'])->assertOk();
        $this->actingAs($this->staff('owner'))->postJson("/api/v1/admin/customer-blocks/{$id}/release", ['note' => 'Coba cabut'])->assertForbidden();
    }

    public function test_catalog_and_content_lists_expose_only_public_ids(): void
    {
        $this->actingAs($this->staff('super-admin'));

        foreach (['/api/v1/admin/categories' => 'data.items', '/api/v1/admin/warehouses' => 'data.items', '/api/v1/admin/gallery-photos' => 'data.items', '/api/v1/admin/faqs' => 'data.items'] as $url => $path) {
            $ids = collect($this->getJson($url)->assertOk()->json($path))->pluck('id');
            $this->assertNotEmpty($ids, $url);
            $this->assertTrue($ids->every(fn ($id) => is_string($id) && Str::isUlid($id)), $url);
        }
    }

    public function test_catalog_routes_reject_numeric_ids_and_keep_permissions(): void
    {
        $category = ProductCategory::firstOrFail();
        $warehouse = Warehouse::where('code', 'MLG')->firstOrFail();
        $this->actingAs($this->staff('store-admin'));

        $this->patchJson("/api/v1/admin/categories/{$category->id}", ['color' => 'rose'])->assertNotFound();
        $this->patchJson("/api/v1/admin/categories/{$category->public_id}", ['color' => 'rose'])->assertOk()->assertJsonPath('data.id', $category->public_id);
        $this->patchJson("/api/v1/admin/warehouses/{$warehouse->id}", ['postal_code' => '65141'])->assertNotFound();

        // Produk memakai ID publik kategori; ID angka ditolak validasi.
        $product = Product::create(['name' => 'Nastar Uji', 'slug' => 'nastar-uji-'.uniqid()]);
        $this->patchJson("/api/v1/admin/products/{$product->public_id}", ['product_category_id' => $category->id])->assertJsonValidationErrors('product_category_id');
        $this->patchJson("/api/v1/admin/products/{$product->public_id}", ['product_category_id' => $category->public_id])
            ->assertOk()->assertJsonPath('data.product_category_id', $category->public_id);
        $this->assertSame($category->id, $product->fresh()->product_category_id, 'Foreign key tetap angka.');

        // Izin tetap: Marketing tidak bisa mengubah gudang walau ID publiknya benar.
        $this->actingAs($this->staff('marketing'))->patchJson("/api/v1/admin/warehouses/{$warehouse->public_id}", ['postal_code' => '65141'])->assertForbidden();
    }

    public function test_marketing_draft_child_routes_need_matching_public_ids(): void
    {
        $marketing = $this->staff('marketing');
        $this->actingAs($marketing);
        $id = $this->postJson('/api/v1/admin/marketing-drafts', ['type' => 'video_script', 'platform' => 'instagram', 'title' => 'Uji', 'body' => 'Isi draf uji'])->assertCreated()->json('data.id');
        $this->assertTrue(Str::isUlid($id));
        $numeric = MarketingDraft::where('public_id', $id)->value('id');

        $this->patchJson("/api/v1/admin/marketing-drafts/{$numeric}", ['title' => 'X'])->assertStatus(405);
        $this->patchJson("/api/v1/admin/marketing-drafts/{$id}", ['type' => 'video_script', 'platform' => 'instagram', 'title' => 'Uji baru', 'body' => 'Isi draf uji'])->assertOk()->assertJsonPath('data.id', $id);
    }

    public function test_roles_and_permissions_use_public_ids(): void
    {
        $admin = User::factory()->create(['mfa_totp_confirmed_at' => now(), 'password' => 'Rahasia-Uji-123']);
        $admin->assignRole('super-admin');
        $this->actingAs($admin);

        $access = $this->getJson('/api/v1/admin/access')->assertOk()->json('data');
        $roleIds = collect($access['roles'])->pluck('id');
        $this->assertTrue($roleIds->every(fn ($id) => is_string($id) && Str::isUlid($id)));
        $this->assertTrue(collect($access['permissions'])->pluck('id')->every(fn ($id) => is_string($id) && Str::isUlid($id)));

        $marketing = Role::where('name', 'marketing')->firstOrFail();
        $this->patchJson("/api/v1/admin/roles/{$marketing->id}", ['password' => 'Rahasia-Uji-123', 'add' => [], 'remove' => []])->assertStatus(405);
        $this->patchJson('/api/v1/admin/roles/'.$marketing->fresh()->getAttribute('public_id'), ['password' => 'Rahasia-Uji-123', 'add' => [], 'remove' => []])->assertOk();

        // Izin tetap: Pemilik tidak bisa mengubah role walau ID publiknya benar.
        $this->actingAs($this->staff('owner'))->patchJson('/api/v1/admin/roles/'.$marketing->fresh()->getAttribute('public_id'), ['password' => 'x', 'add' => [], 'remove' => []])->assertForbidden();
    }

    public function test_audit_log_and_stock_history_show_public_ids_only(): void
    {
        $product = Product::create(['name' => 'Nastar Log', 'slug' => 'nastar-log-'.uniqid()]);
        $variant = $product->variants()->create(['sku' => 'LOG-01', 'name' => 'Standar', 'price' => 10000, 'weight_gram' => 100]);
        $this->actingAs($this->staff('store-admin'));
        $this->postJson('/api/v1/admin/stock/adjust', [
            'warehouse_id' => Warehouse::where('code', 'MLG')->value('public_id'), 'product_variant_id' => $variant->public_id,
            'type' => 'restock', 'quantity_change' => 5, 'note' => 'Uji riwayat',
        ])->assertOk();
        $this->patchJson("/api/v1/admin/products/{$product->public_id}", ['name' => 'Nastar Log Baru'])->assertOk();

        $rows = collect($this->getJson('/api/v1/admin/stock/history')->assertOk()->json('data.items'));
        $this->assertTrue($rows->pluck('id')->every(fn ($id) => is_string($id) && Str::isUlid($id)));

        $this->actingAs($this->staff('owner'));
        $logs = collect($this->getJson('/api/v1/admin/audit-logs')->assertOk()->json('data.items'));
        $this->assertTrue($logs->pluck('id')->every(fn ($id) => is_string($id) && Str::isUlid($id)));
        $updated = $logs->firstWhere('action', 'product.updated');
        $this->assertSame('Product '.$product->public_id, $updated['target']);
        $this->assertStringNotContainsString('#', $updated['target']);
    }

    public function test_backfill_is_idempotent(): void
    {
        $product = Product::create(['name' => 'Pia', 'slug' => 'pia-'.uniqid()]);
        $migration = require database_path('migrations/2026_11_18_000030_add_public_id_to_products_and_users.php');

        $migration::backfill('products');
        $migration::backfill('products');

        $this->assertSame($product->public_id, $product->fresh()->public_id);
    }
}
