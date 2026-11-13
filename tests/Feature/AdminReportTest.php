<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderStatusService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $product = Product::create(['name' => 'Nastar', 'slug' => 'nastar', 'is_published' => true]);
        $variant = $product->variants()->create(['sku' => 'NAS-01', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);
        InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->value('id'), 'product_variant_id' => $variant->id, 'quantity' => 10]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function paidOrder(): void
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2])->assertOk();

        $ref = $this->withHeader('Idempotency-Key', 'key-'.str()->random(20))->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi Rahasia',
            'customer_email' => 'budi.rahasia@example.com',
            'customer_phone' => '081234567890',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt',
            'service' => 'ez',
        ])->assertCreated()->json('data.public_ref');

        app(OrderStatusService::class)->markPaid(Order::where('public_ref', $ref)->firstOrFail());
    }

    public function test_marketing_gets_counts_but_no_money_or_customer_data(): void
    {
        $this->paidOrder();

        $response = $this->actingAs($this->staff('marketing'))->getJson('/api/v1/admin/reports')->assertOk();
        $json = $response->getContent();

        $this->assertFalse($response->json('data.money'));
        $this->assertNull($response->json('data.sales.revenue_chart'));
        $this->assertNull($response->json('data.top_products.0.revenue'));
        $this->assertSame(2, $response->json('data.top_products.0.qty'));
        foreach (['Budi Rahasia', 'budi.rahasia', '081234567890', 'ORD'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_owner_also_gets_revenue(): void
    {
        $this->paidOrder();

        $response = $this->actingAs($this->staff('owner'))->getJson('/api/v1/admin/reports')->assertOk();

        $this->assertTrue($response->json('data.money'));
        $this->assertSame('rupiah', $response->json('data.sales.revenue_chart.format'));
        $series = collect($response->json('data.sales.revenue_chart.series'))->keyBy('key');
        $this->assertSame(['revenue', 'refunded', 'net'], $series->keys()->all());
        $this->assertSame($series['revenue']['values'][9], $series['net']['values'][9]); // tanpa dana yang dikembalikan, bersih = kotor
        $this->assertGreaterThan(0, $series['net']['values'][9]);
        $this->assertGreaterThan(0, $response->json('data.top_products.0.revenue'));
        $this->assertStringNotContainsString('Budi Rahasia', $response->getContent());
    }

    public function test_month_boundaries_follow_jakarta_time_not_utc(): void
    {
        // 03:30 WIB tanggal 1 November: sudah November di toko, masih 31 Oktober menurut UTC.
        $this->travelTo(CarbonImmutable::parse('2026-10-31 20:30:00', 'UTC'));
        $this->beforeApplicationDestroyed(fn () => $this->travelBack());

        $this->paidOrder();
        $this->paidOrder();
        // 23:30 WIB 31 Oktober, masih Oktober di toko.
        Order::orderByDesc('id')->first()->forceFill(['paid_at' => CarbonImmutable::parse('2026-10-31 16:30:00', 'UTC')])->save();

        $data = $this->actingAs($this->staff('owner'))->getJson('/api/v1/admin/reports')->assertOk()->json('data.sales');

        $this->assertSame('Nov', collect($data['chart']['labels'])->last());
        $orders = $data['chart']['series'][0]['values'];
        $this->assertSame(1, $orders[9]); // November
        $this->assertSame(1, $orders[8]); // Oktober
        $this->assertGreaterThan(0, $data['revenue_chart']['series'][0]['values'][8]);
    }

    public function test_other_roles_and_guests_are_refused(): void
    {
        foreach (['store-admin', 'customer-service', 'warehouse'] as $role) {
            $this->actingAs($this->staff($role))->getJson('/api/v1/admin/reports')->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/reports')->assertUnauthorized();
    }
}
