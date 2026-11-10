<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\Warehouse;
use App\Services\Shipping\ShippingProvider;
use App\Services\Shipping\UnavailableShippingProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chat toko tanpa model AI (D-067): cek status pesanan dan cek ongkir.
 */
class ChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    private function variant(string $sku = 'NAS-01', array $attributes = [], array $product = []): ProductVariant
    {
        $model = Product::create(['name' => "Produk {$sku}", 'slug' => strtolower($sku), 'is_published' => true, ...$product]);
        $variant = $model->variants()->create(['sku' => $sku, 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500, ...$attributes]);
        InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->value('id'), 'product_variant_id' => $variant->id, 'quantity' => 10]);

        return $variant;
    }

    private function placeOrder(): Order
    {
        $this->variant();
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2])->assertOk();
        $ref = $this->withHeader('Idempotency-Key', 'key-0000000000000001')->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi Uji',
            'customer_email' => 'Budi@Example.com',
            'customer_phone' => '0812-3456-7890',
            'address' => ['street' => 'Jl. Rahasia 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt',
            'service' => 'ez',
        ])->json('data.public_ref');
        $this->flushSession();

        return Order::where('public_ref', $ref)->firstOrFail();
    }

    public function test_order_status_returns_only_safe_fields(): void
    {
        $order = $this->placeOrder();
        Shipment::forceCreate(['order_id' => $order->id, 'provider' => 'test', 'courier_code' => 'jnt', 'service_code' => 'ez', 'waybill_id' => 'JNT123', 'status' => 'shipped', 'shipping_fee' => 24000]);

        $response = $this->postJson('/api/v1/chat/order-status', ['order_number' => strtolower($order->order_number), 'contact' => 'budi@example.com'])
            ->assertOk()
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.status', Order::PENDING_PAYMENT)
            ->assertJsonPath('data.courier', 'jnt')
            ->assertJsonPath('data.waybill', 'JNT123');

        $body = json_encode($response->json());
        foreach (['Rahasia', 'Budi', '0812', '6281234567890', '65111', 'example.com'] as $private) {
            $this->assertStringNotContainsString($private, $body);
        }

        $this->postJson('/api/v1/chat/order-status', ['order_number' => $order->order_number, 'contact' => '+62 812 3456 7890'])->assertOk();
    }

    public function test_order_status_fails_the_same_way_for_wrong_contact_and_missing_order(): void
    {
        $order = $this->placeOrder();

        $wrong = $this->postJson('/api/v1/chat/order-status', ['order_number' => $order->order_number, 'contact' => 'lain@example.com'])->assertNotFound();
        $missing = $this->postJson('/api/v1/chat/order-status', ['order_number' => 'TF0000-000000', 'contact' => 'budi@example.com'])->assertNotFound();

        $this->assertSame($wrong->json(), $missing->json());
    }

    public function test_order_status_is_rate_limited(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/chat/order-status', ['order_number' => 'TF0000-000000', 'contact' => 'x@example.com'])->assertNotFound();
        }

        $this->postJson('/api/v1/chat/order-status', ['order_number' => 'TF0000-000000', 'contact' => 'x@example.com'])->assertTooManyRequests();
    }

    public function test_shipping_rates_use_server_weight_and_ignore_client_weight(): void
    {
        $this->variant();

        // 3 x 500 g = 1500 g, dibulatkan 2 kg oleh penyedia tarif uji.
        $this->postJson('/api/v1/chat/shipping-rates', ['sku' => 'NAS-01', 'quantity' => 3, 'postal_code' => '65111', 'weight_gram' => 1])
            ->assertOk()
            ->assertJsonPath('data.rates.0.courier', 'jnt')
            ->assertJsonPath('data.rates.0.fee', 24000);
    }

    public function test_shipping_rates_cap_quantity_at_the_variant_limit(): void
    {
        $this->variant('NAS-01', ['max_order_quantity' => 2]);

        // 999 diminta, tetapi batas varian 2 x 500 g = 1 kg.
        $this->postJson('/api/v1/chat/shipping-rates', ['sku' => 'NAS-01', 'quantity' => 999, 'postal_code' => '65111'])
            ->assertOk()
            ->assertJsonPath('data.rates.0.fee', 12000);
    }

    public function test_shipping_rates_reject_unknown_draft_and_inactive_products(): void
    {
        $this->variant('DRAF', [], ['is_published' => false]);
        $this->variant('NONAKTIF', ['is_active' => false]);

        foreach (['DRAF', 'NONAKTIF', 'TIDAK-ADA'] as $sku) {
            $this->postJson('/api/v1/chat/shipping-rates', ['sku' => $sku, 'quantity' => 1, 'postal_code' => '65111'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('sku');
        }
    }

    public function test_shipping_rates_validate_input(): void
    {
        $this->variant();

        $this->postJson('/api/v1/chat/shipping-rates', ['sku' => 'NAS-01', 'quantity' => 1, 'postal_code' => '123'])->assertJsonValidationErrors('postal_code');
        $this->postJson('/api/v1/chat/shipping-rates', ['sku' => 'NAS-01', 'quantity' => 0, 'postal_code' => '65111'])->assertJsonValidationErrors('quantity');
        $this->postJson('/api/v1/chat/shipping-rates', ['sku' => 'NAS-01', 'quantity' => 1000, 'postal_code' => '65111'])->assertJsonValidationErrors('quantity');
    }

    public function test_shipping_rates_report_unavailable_provider(): void
    {
        $this->variant();
        $this->app->bind(ShippingProvider::class, UnavailableShippingProvider::class);

        $this->postJson('/api/v1/chat/shipping-rates', ['sku' => 'NAS-01', 'quantity' => 1, 'postal_code' => '65111'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shipping');
    }

    public function test_shipping_rates_are_rate_limited(): void
    {
        $this->variant();

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/chat/shipping-rates', ['sku' => 'NAS-01', 'quantity' => 1, 'postal_code' => '65111'])->assertOk();
        }

        $this->postJson('/api/v1/chat/shipping-rates', ['sku' => 'NAS-01', 'quantity' => 1, 'postal_code' => '65111'])->assertTooManyRequests();

        // Batasnya per jam, bukan per menit (D-076).
        $this->travel(30)->minutes();
        $this->postJson('/api/v1/chat/shipping-rates', ['sku' => 'NAS-01', 'quantity' => 1, 'postal_code' => '65111'])->assertTooManyRequests();
        $this->travel(31)->minutes();
        $this->postJson('/api/v1/chat/shipping-rates', ['sku' => 'NAS-01', 'quantity' => 1, 'postal_code' => '65111'])->assertOk();
    }
}
