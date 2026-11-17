<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderExpiryService;
use App\Services\OrderReviewService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tinjauan pesanan (D-121, D-132): tenggat 1 hari kerja, setuju/tolak oleh Admin Toko, antrean peringatan, dan penutupan otomatis.
 */
class OrderReviewTest extends TestCase
{
    use RefreshDatabase;

    private InventoryStock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $variant = Product::create(['name' => 'Hampers', 'slug' => 'hampers', 'is_published' => true])
            ->variants()->create(['sku' => 'HMP-01', 'name' => 'Standar', 'price' => 1500000, 'weight_gram' => 500]);
        $this->stock = InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->value('id'), 'product_variant_id' => $variant->id, 'quantity' => 10]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    /** Pesanan Rp3 juta lebih lewat checkout sungguhan, jadi stok ikut ditahan. */
    private function awaitingOrder(): Order
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'HMP-01', 'quantity' => 2])->assertOk();
        $this->withHeader('Idempotency-Key', 'key-review-000000001')->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi Uji', 'customer_email' => 'budi@example.com', 'customer_phone' => '0812-3456-7890',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt', 'service' => 'ez',
        ])->assertCreated();
        $this->flushSession();

        return Order::firstOrFail();
    }

    /** @return array<string, array{string, string}> waktu dibuat (WIB) => tenggat (WIB) */
    public static function deadlines(): array
    {
        return [
            'Senin pagi sebelum buka' => ['2026-11-16 08:00', '2026-11-16 16:00'],
            'Senin siang' => ['2026-11-16 13:30', '2026-11-17 13:30'],
            'Sabtu siang melewati Minggu' => ['2026-11-21 14:00', '2026-11-23 14:00'],
            'Sabtu malam saat tutup' => ['2026-11-21 20:00', '2026-11-23 16:00'],
            'Minggu' => ['2026-11-22 10:00', '2026-11-23 16:00'],
        ];
    }

    #[DataProvider('deadlines')]
    public function test_deadline_is_one_working_day_paused_while_closed(string $created, string $due): void
    {
        $from = CarbonImmutable::parse($created, 'Asia/Jakarta');

        $this->assertSame($due, app(OrderReviewService::class)->dueAt($from)->setTimezone('Asia/Jakarta')->format('Y-m-d H:i'));
    }

    public function test_store_admin_approves_and_order_becomes_payable(): void
    {
        $order = $this->awaitingOrder();
        $admin = $this->staff('store-admin');

        $this->actingAs($admin)->postJson("/api/v1/admin/orders/{$order->id}/approve", ['note' => 'Pelanggan lama'])->assertOk();

        $order->refresh();
        $this->assertSame(Order::PENDING_PAYMENT, $order->status);
        $this->assertSame($admin->id, $order->reviewed_by);
        $this->assertEqualsWithDelta(now()->addMinutes(60)->getTimestamp(), $order->expires_at->getTimestamp(), 5);
        $this->assertSame(2, $this->stock->fresh()->reserved);
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'from_status' => Order::AWAITING_APPROVAL, 'to_status' => Order::PENDING_PAYMENT, 'actor_user_id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.approved', 'auditable_id' => $order->id]);

        // Tidak bisa disetujui dua kali.
        $this->actingAs($admin)->postJson("/api/v1/admin/orders/{$order->id}/approve")->assertUnprocessable();
    }

    public function test_reject_requires_reason_and_releases_stock(): void
    {
        $order = $this->awaitingOrder();
        $admin = $this->staff('store-admin');

        $this->actingAs($admin)->postJson("/api/v1/admin/orders/{$order->id}/reject", ['note' => '  '])->assertJsonValidationErrors('note');
        $this->actingAs($admin)->postJson("/api/v1/admin/orders/{$order->id}/reject", ['note' => 'Alamat tidak valid'])->assertOk();

        $order->refresh();
        $this->assertSame(Order::REJECTED, $order->status);
        $this->assertSame('cancelled', $order->payment_status);
        $this->assertSame('Alamat tidak valid', $order->review_note);
        $this->assertSame(0, $this->stock->fresh()->reserved);
    }

    public function test_only_store_admin_and_super_admin_decide(): void
    {
        $order = $this->awaitingOrder();

        foreach (['owner', 'customer-service', 'warehouse', 'marketing'] as $role) {
            $this->actingAs($this->staff($role))->postJson("/api/v1/admin/orders/{$order->id}/approve")->assertForbidden();
        }
        $this->actingAs($this->staff('super-admin'))->postJson("/api/v1/admin/orders/{$order->id}/reject", ['note' => 'Uji'])->assertOk();
    }

    public function test_review_queue_is_visible_to_store_admin_owner_and_super_admin_only(): void
    {
        $order = $this->awaitingOrder();

        foreach (['store-admin', 'owner', 'super-admin'] as $role) {
            $this->actingAs($this->staff($role))->getJson('/api/v1/admin/orders/review-queue')->assertOk()
                ->assertJsonPath('data.awaiting_total', 1)->assertJsonPath('data.awaiting.0.order_number', $order->order_number);
        }
        foreach (['customer-service', 'warehouse', 'marketing'] as $role) {
            $this->actingAs($this->staff($role))->getJson('/api/v1/admin/orders/review-queue')->assertForbidden();
        }

        // Tanda tinjau di daftar pesanan hanya untuk pemegang orders.review_alert.
        $this->actingAs($this->staff('owner'))->getJson('/api/v1/admin/orders?review=pending')->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.items.0.review.pending', true);
        $this->actingAs($this->staff('customer-service'))->getJson('/api/v1/admin/orders')->assertJsonMissingPath('data.items.0.review');
    }

    public function test_flagged_order_is_marked_reviewed(): void
    {
        $order = $this->awaitingOrder();
        $order->update(['status' => Order::PENDING_PAYMENT]);
        $admin = $this->staff('store-admin');

        $this->actingAs($admin)->postJson("/api/v1/admin/orders/{$order->id}/reviewed")->assertOk();
        $this->assertNotNull($order->fresh()->reviewed_at);
        $this->actingAs($admin)->postJson("/api/v1/admin/orders/{$order->id}/reviewed")->assertUnprocessable();
    }

    public function test_awaiting_order_past_deadline_is_closed_and_stock_released(): void
    {
        $order = $this->awaitingOrder();
        $order->update(['expires_at' => now()->subMinute()]);

        app(OrderExpiryService::class)->expireDue();

        $this->assertSame(Order::EXPIRED, $order->fresh()->status);
        $this->assertSame(0, $this->stock->fresh()->reserved);
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'from_status' => Order::AWAITING_APPROVAL, 'to_status' => Order::EXPIRED, 'reason' => 'Batas tinjau lewat']);
    }
}
