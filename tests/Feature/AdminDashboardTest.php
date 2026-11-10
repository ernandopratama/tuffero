<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DashboardService;
use App\Services\OrderStatusService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = ['super-admin', 'owner', 'store-admin', 'customer-service', 'warehouse', 'marketing'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $product = Product::create(['name' => 'Nastar', 'slug' => 'nastar', 'is_published' => true]);
        $variant = $product->variants()->create(['sku' => 'NAS-01', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);
        InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->value('id'), 'product_variant_id' => $variant->id, 'quantity' => 10]);
    }

    private function staff(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now(), ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    private function paidOrder(int $quantity = 2): Order
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => $quantity])->assertOk();

        $ref = $this->withHeader('Idempotency-Key', 'key-'.str()->random(20))->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi Rahasia',
            'customer_email' => 'budi.rahasia@example.com',
            'customer_phone' => '081234567890',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt',
            'service' => 'ez',
        ])->assertCreated()->json('data.public_ref');

        return app(OrderStatusService::class)->markPaid(Order::where('public_ref', $ref)->firstOrFail());
    }

    /** @return array<string, mixed> */
    private function dashboard(string $role): array
    {
        return $this->actingAs($this->staff($role))->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');
    }

    private function kpi(array $data, string $key): ?array
    {
        return collect($data['kpis'])->firstWhere('key', $key);
    }

    /** @return array<string, array{string}> */
    public static function roles(): array
    {
        return array_combine(self::ROLES, array_map(fn ($r) => [$r], self::ROLES));
    }

    #[DataProvider('roles')]
    public function test_every_role_gets_its_own_complete_dashboard(string $role): void
    {
        $data = $this->dashboard($role);

        $this->assertSame($role, $data['role']);
        $this->assertCount(3, $data['kpis']);
        $this->assertNotEmpty($data['chart']['labels']);
        $this->assertNotEmpty($data['chart']['series']);
        $this->assertNotNull($data['gauge']);
        $this->assertCount(3, $data['lists']);
        $this->assertIsString($data['summary']);
    }

    public function test_guest_and_roleless_accounts_are_refused(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();

        $this->actingAs(User::factory()->create(['mfa_totp_confirmed_at' => now()]))->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_inactive_staff_cannot_open_the_dashboard(): void
    {
        $this->actingAs($this->staff('owner', ['is_active' => false]))->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    }

    public function test_warehouse_never_receives_money_or_customer_data(): void
    {
        $this->paidOrder();

        $json = $this->actingAs($this->staff('warehouse'))->getJson('/api/v1/admin/dashboard')->assertOk()->getContent();

        foreach (['Budi Rahasia', 'budi.rahasia', '081234567890', 'rupiah', '"amount":', 'revenue'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_marketing_never_receives_orders_customers_or_revenue(): void
    {
        $this->paidOrder();

        $response = $this->actingAs($this->staff('marketing'))->getJson('/api/v1/admin/dashboard')->assertOk();
        $json = $response->getContent();

        foreach (['Budi Rahasia', 'budi.rahasia', 'rupiah', 'revenue', 'ORD', '"amount":1'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }

        $this->assertSame(['orders', 'units'], array_column($response->json('data.chart.series'), 'key'));
    }

    public function test_customer_service_does_not_see_revenue(): void
    {
        $this->paidOrder();

        $data = $this->dashboard('customer-service');

        $this->assertNull($this->kpi($data, 'revenue_month'));
        $this->assertStringNotContainsString('rupiah', json_encode($data));
    }

    public function test_counts_follow_real_orders(): void
    {
        $order = $this->paidOrder(2);

        $store = $this->dashboard('store-admin');
        $this->assertSame(1, $this->kpi($store, 'to_process')['value']);
        $this->assertContains('to_process', array_column($store['attention'], 'key'));

        $owner = $this->dashboard('owner');
        $this->assertSame((int) $order->total, $this->kpi($owner, 'revenue_month')['value']);
        $this->assertSame('rupiah', $this->kpi($owner, 'revenue_month')['format']);

        $this->assertSame(1, $this->kpi($this->dashboard('super-admin'), 'orders_today')['value']);
        $this->assertSame(1, $this->kpi($this->dashboard('warehouse'), 'to_pack')['value']);
    }

    public function test_top_products_show_revenue_only_to_roles_that_may_see_payments(): void
    {
        $this->paidOrder(3);

        $owner = collect($this->dashboard('owner')['lists'])->firstWhere('key', 'top_products');
        $store = collect($this->dashboard('store-admin')['lists'])->firstWhere('key', 'top_products');

        $this->assertSame('Nastar', $owner['rows'][0]['title']);
        $this->assertSame('3 terjual', $owner['rows'][0]['subtitle']);
        $this->assertIsInt($owner['rows'][0]['amount']);
        $this->assertIsInt($store['rows'][0]['amount']);
    }

    public function test_cancellation_queues_follow_the_two_approval_flow(): void
    {
        $order = $this->paidOrder();
        $this->withSession(["order_access.{$order->public_ref}" => true])
            ->postJson("/api/v1/orders/{$order->public_ref}/cancellation", ['reason' => 'Salah pesan', 'channel' => 'whatsapp'])->assertCreated();
        $number = OrderCancellationRequest::firstOrFail()->request_number;

        $cs = $this->dashboard('customer-service');
        $this->assertSame(1, $this->kpi($cs, 'cancel_validate_pending')['value']);
        $this->assertSame($number, collect($cs['lists'])->firstWhere('key', 'cancel_validate_queue')['rows'][0]['title']);

        $this->assertNull(collect($this->dashboard('owner')['lists'])->firstWhere('key', 'cancel_final_queue')['rows'][0] ?? null);

        OrderCancellationRequest::query()->update(['status' => OrderCancellationRequest::STORE_APPROVED]);
        $this->assertSame(1, $this->kpi($this->dashboard('owner'), 'cancel_final_pending')['value']);
        $this->assertSame($number, collect($this->dashboard('owner')['lists'])->firstWhere('key', 'cancel_final_queue')['rows'][0]['title']);
    }

    public function test_overdue_cancellations_are_only_flagged_never_changed(): void
    {
        $order = $this->paidOrder();
        $this->withSession(["order_access.{$order->public_ref}" => true])
            ->postJson("/api/v1/orders/{$order->public_ref}/cancellation", ['reason' => 'Salah pesan', 'channel' => 'email'])->assertCreated();
        OrderCancellationRequest::query()->update(['due_at' => now()->subDay()]);

        $data = $this->dashboard('customer-service');

        $this->assertSame(1, $this->kpi($data, 'cancel_overdue')['value']);
        $this->assertSame(OrderCancellationRequest::SUBMITTED, OrderCancellationRequest::firstOrFail()->status);
    }

    public function test_low_and_out_of_stock_are_reported_with_the_proposed_threshold(): void
    {
        $this->assertSame(10, DashboardService::LOW_STOCK);

        $this->paidOrder(2);
        $data = $this->dashboard('warehouse');

        $this->assertSame(1, $this->kpi($data, 'low_stock')['value']);
        $this->assertContains('stock_low', array_column($data['attention'], 'key'));

        InventoryStock::query()->update(['quantity' => 2, 'reserved' => 2]);
        $this->assertContains('stock_out', array_column($this->dashboard('warehouse')['attention'], 'key'));
    }

    public function test_security_gauge_counts_staff_with_and_without_mfa(): void
    {
        $this->staff('owner', ['mfa_totp_confirmed_at' => null]);
        $this->staff('marketing');

        $gauge = $this->dashboard('super-admin')['gauge'];

        // super admin pemanggil dan marketing punya MFA, pemilik tidak.
        $this->assertSame(67, $gauge['percent']);
        $this->assertSame('1 akun', $gauge['metrics'][1]['value']);
    }

    public function test_gauges_are_empty_not_zero_when_there_is_nothing_to_measure(): void
    {
        $this->assertNull($this->dashboard('owner')['gauge']['percent']);
        $this->assertNull($this->dashboard('marketing')['gauge']['percent']);
    }

    public function test_dashboard_makes_a_bounded_number_of_queries(): void
    {
        $user = $this->staff('super-admin');
        $this->actingAs($user);
        $queries = 0;
        \DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->getJson('/api/v1/admin/dashboard')->assertOk();

        $this->assertLessThan(120, $queries);
    }
}
