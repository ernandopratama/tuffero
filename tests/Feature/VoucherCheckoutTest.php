<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Models\Warehouse;
use App\Services\OrderExpiryService;
use App\Services\VoucherService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Voucher di checkout (D-066, D-069): dihitung di server, dipisah Non Member / Member, kuota dikunci dan dikembalikan.
 * Keranjang uji: 2 x Rp50.000 = Rp100.000, ongkir uji Rp12.000.
 */
class VoucherCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'MLG')->firstOrFail();

        $product = Product::create(['name' => 'Produk Uji', 'slug' => 'produk-uji', 'is_published' => true]);
        $variant = $product->variants()->create(['sku' => 'NAS-01', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);
        InventoryStock::create(['warehouse_id' => $this->warehouse->id, 'product_variant_id' => $variant->id, 'quantity' => 20]);
    }

    private function voucher(array $attributes = []): Voucher
    {
        return Voucher::create([
            'code' => 'HEMAT15', 'type' => 'fixed', 'value' => 15000, 'min_subtotal' => 0,
            'is_active' => true, 'per_customer_limit' => null, ...$attributes,
        ]);
    }

    private function checkout(?string $code, string $key = 'key-0000000000000001', string $phone = '0812-3456-7890')
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2])->assertOk();

        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/checkout', array_filter([
            'customer_name' => 'Budi Uji',
            'customer_email' => 'budi@example.com',
            'customer_phone' => $phone,
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt',
            'service' => 'ez',
            'voucher_code' => $code,
        ]));
    }

    private function member(): User
    {
        return User::factory()->create();
    }

    public function test_fixed_voucher_reduces_the_total_and_keeps_the_real_shipping_fee(): void
    {
        $voucher = $this->voucher();

        $this->checkout('hemat15 ')->assertCreated();

        $order = Order::firstOrFail();
        $this->assertSame(100000, $order->subtotal);
        $this->assertSame(15000, $order->discount_total);
        $this->assertSame(12000, $order->shipping_fee);
        $this->assertSame(97000, $order->total);
        $this->assertSame('HEMAT15', $order->voucher_code);
        $this->assertSame(1, $voucher->fresh()->used_count);
        $this->assertSame(15000, VoucherRedemption::firstOrFail()->amount);
    }

    public function test_percent_voucher_is_capped_by_max_discount(): void
    {
        $this->voucher(['type' => 'percent', 'value' => 20, 'max_discount' => 10000]);

        $this->checkout('HEMAT15')->assertCreated();

        $this->assertSame(10000, Order::firstOrFail()->discount_total);
    }

    public function test_discount_never_exceeds_the_subtotal(): void
    {
        $this->voucher(['value' => 500000]);

        $this->checkout('HEMAT15')->assertCreated();

        $order = Order::firstOrFail();
        $this->assertSame(100000, $order->discount_total);
        $this->assertSame(12000, $order->total);
    }

    public function test_client_cannot_set_the_discount_directly(): void
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2])->assertOk();

        $this->withHeader('Idempotency-Key', 'key-0000000000000009')->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi', 'customer_email' => 'b@example.com', 'customer_phone' => '081234567890',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt', 'service' => 'ez', 'discount_total' => 99999, 'total' => 1,
        ])->assertCreated();

        $this->assertSame(0, Order::firstOrFail()->discount_total);
        $this->assertSame(112000, Order::firstOrFail()->total);
    }

    public function test_minimum_spend_is_checked_and_a_failed_voucher_creates_no_order(): void
    {
        $voucher = $this->voucher(['min_subtotal' => 150000]);

        $this->checkout('HEMAT15')->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_min_subtotal');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, $voucher->fresh()->used_count);
        $this->assertSame(0, InventoryStock::firstOrFail()->reserved);
    }

    public function test_unknown_inactive_not_started_and_expired_codes_are_invalid(): void
    {
        $this->voucher(['code' => 'MATI', 'is_active' => false]);
        $this->voucher(['code' => 'BELUM', 'starts_at' => now()->addDay()]);
        $this->voucher(['code' => 'LEWAT', 'ends_at' => now()->subMinute()]);

        foreach (['TIDAKADA', 'MATI', 'BELUM', 'LEWAT'] as $code) {
            $this->checkout($code)->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_invalid');
        }
        $this->assertSame(0, Order::count());
    }

    public function test_member_only_voucher_needs_a_logged_in_customer_and_guest_only_rejects_one(): void
    {
        $this->voucher(['code' => 'MEMBERAJA', 'audience' => 'member']);
        $this->voucher(['code' => 'TAMUAJA', 'audience' => 'guest']);

        $this->checkout('MEMBERAJA')->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_member_only');
        $this->checkout('TAMUAJA', 'key-0000000000000002')->assertCreated();

        $this->actingAs($this->member());
        $this->checkout('TAMUAJA', 'key-0000000000000003', '0812-0000-1111')->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_guest_only');
        $this->checkout('MEMBERAJA', 'key-0000000000000004', '0812-0000-1111')->assertCreated();
    }

    public function test_staff_account_counts_as_non_member(): void
    {
        $this->voucher(['audience' => 'member']);
        $staff = User::factory()->create();
        $staff->assignRole('store-admin');

        $this->actingAs($staff);
        $this->checkout('HEMAT15')->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_member_only');
    }

    public function test_quota_is_enforced_and_the_last_use_wins_only_once(): void
    {
        $this->voucher(['quota' => 1]);

        $this->checkout('HEMAT15', 'key-0000000000000001', '0812-1111-0001')->assertCreated();
        $this->checkout('HEMAT15', 'key-0000000000000002', '0812-1111-0002')->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_exhausted');
        $this->assertSame(1, Order::count());
    }

    public function test_per_customer_limit_uses_the_phone_number(): void
    {
        $this->voucher(['per_customer_limit' => 1]);

        $this->checkout('HEMAT15', 'key-0000000000000001', '0812-2222-0001')->assertCreated();
        $this->checkout('HEMAT15', 'key-0000000000000002', '0812-2222-0001')->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_used');
        $this->checkout('HEMAT15', 'key-0000000000000003', '0812-2222-0002')->assertCreated();
    }

    public function test_resubmitting_the_same_request_does_not_use_the_voucher_twice(): void
    {
        $voucher = $this->voucher();

        $this->checkout('HEMAT15', 'key-0000000000000001')->assertCreated();
        $this->checkout('HEMAT15', 'key-0000000000000001')->assertStatus(200);

        $this->assertSame(1, $voucher->fresh()->used_count);
        $this->assertSame(1, VoucherRedemption::count());
    }

    public function test_expired_order_gives_the_voucher_back(): void
    {
        $voucher = $this->voucher(['quota' => 1, 'per_customer_limit' => 1]);
        $this->checkout('HEMAT15')->assertCreated();
        Order::query()->update(['expires_at' => now()->subMinute()]);

        app(OrderExpiryService::class)->expireDue();

        $this->assertSame(0, $voucher->fresh()->used_count);
        $this->assertNotNull(VoucherRedemption::firstOrFail()->released_at);
        // Kuota dan batas per pelanggan kembali: nomor yang sama bisa memakai lagi.
        $this->checkout('HEMAT15', 'key-0000000000000002')->assertCreated();
    }

    public function test_release_is_idempotent_and_never_goes_below_zero(): void
    {
        $voucher = $this->voucher();
        $this->checkout('HEMAT15')->assertCreated();
        $order = Order::firstOrFail();

        app(VoucherService::class)->release($order);
        app(VoucherService::class)->release($order);

        $this->assertSame(0, $voucher->fresh()->used_count);
    }

    public function test_picker_lists_only_vouchers_that_apply_and_greys_out_the_minimum_spend_ones(): void
    {
        $this->voucher(['code' => 'UMUM', 'description' => 'Hemat 15 rb']);
        $this->voucher(['code' => 'BESAR', 'min_subtotal' => 300000]);
        $this->voucher(['code' => 'RAHASIA', 'is_listed' => false]);
        $this->voucher(['code' => 'MEMBERAJA', 'audience' => 'member']);
        $this->voucher(['code' => 'HABIS', 'quota' => 1])->forceFill(['used_count' => 1])->save();
        $this->voucher(['code' => 'MATI', 'is_active' => false]);
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2])->assertOk();

        $codes = fn ($response) => collect($response->json('data.vouchers'))->pluck('code')->all();

        $guest = $this->getJson('/api/v1/vouchers')->assertOk();
        $this->assertSame(['UMUM', 'BESAR'], $codes($guest));
        $this->assertTrue($guest->json('data.vouchers.0.eligible'));
        $this->assertSame(15000, $guest->json('data.vouchers.0.discount'));
        $this->assertFalse($guest->json('data.vouchers.1.eligible'));
        $this->assertSame(200000, $guest->json('data.vouchers.1.missing'));

        $this->actingAs($this->member());
        $this->assertSame(['UMUM', 'MEMBERAJA', 'BESAR'], $codes($this->getJson('/api/v1/vouchers')));
    }

    public function test_check_endpoint_returns_the_discount_without_using_the_voucher(): void
    {
        $voucher = $this->voucher(['type' => 'percent', 'value' => 10]);
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2])->assertOk();

        $this->postJson('/api/v1/vouchers/check', ['code' => 'hemat15'])->assertOk()->assertJsonPath('data.code', 'HEMAT15')->assertJsonPath('data.discount', 10000);
        $this->postJson('/api/v1/vouchers/check', ['code' => 'SALAH'])->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_invalid');
        $this->postJson('/api/v1/vouchers/check', [])->assertUnprocessable();

        $this->assertSame(0, $voucher->fresh()->used_count);
    }

    public function test_the_order_page_data_exposes_the_discount_and_code(): void
    {
        $this->voucher();
        $ref = $this->checkout('HEMAT15')->json('data.public_ref');

        $this->getJson("/api/v1/orders/{$ref}")->assertOk()
            ->assertJsonPath('data.discount_total', 15000)
            ->assertJsonPath('data.voucher_code', 'HEMAT15')
            ->assertJsonPath('data.total', 97000);
    }

    public function test_admin_can_set_segment_description_and_visibility(): void
    {
        $admin = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $admin->assignRole('store-admin');

        $this->actingAs($admin)->postJson('/api/v1/admin/vouchers', [
            'code' => 'MEMBERBARU', 'description' => 'Khusus member', 'type' => 'fixed', 'value' => 5000, 'min_subtotal' => 0,
            'audience' => 'member', 'is_listed' => false, 'per_customer_limit' => 2, 'is_active' => true,
        ])->assertCreated();

        $voucher = Voucher::where('code', 'MEMBERBARU')->firstOrFail();
        $this->assertSame('member', $voucher->audience);
        $this->assertFalse($voucher->is_listed);
        $this->assertSame(2, $voucher->per_customer_limit);

        $this->postJson('/api/v1/admin/vouchers', [
            'code' => 'SALAHSEGMEN', 'type' => 'fixed', 'value' => 5000, 'min_subtotal' => 0, 'audience' => 'vip', 'is_active' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('audience');
    }

    /** @param list<string> $codes */
    private function checkoutWithCodes(array $codes, string $key = 'key-0000000000000009')
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2])->assertOk();

        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi Uji',
            'customer_email' => 'budi@example.com',
            'customer_phone' => '0812-3456-7890',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt',
            'service' => 'ez',
            'voucher_codes' => $codes,
        ]);
    }

    public function test_two_stackable_vouchers_apply_in_order_on_the_remaining_amount(): void
    {
        $first = $this->voucher(['code' => 'POTONG50', 'value' => 50000, 'stackable' => true]);
        $second = $this->voucher(['code' => 'SETENGAH', 'type' => 'percent', 'value' => 50, 'stackable' => true]);

        $this->checkoutWithCodes(['POTONG50', 'SETENGAH'])->assertCreated();

        // Rp100.000 - Rp50.000 = Rp50.000; 50% dari sisa itu Rp25.000, bukan 50% dari Rp100.000.
        $order = Order::firstOrFail();
        $this->assertSame(75000, $order->discount_total);
        $this->assertSame(100000 - 75000 + 12000, $order->total);
        $this->assertSame(['POTONG50', 'SETENGAH'], [$order->voucher_code, $order->voucher_code_2]);
        $this->assertSame(1, $first->fresh()->used_count);
        $this->assertSame(1, $second->fresh()->used_count);
        $this->assertSame(2, VoucherRedemption::count());
    }

    public function test_two_vouchers_need_both_to_be_stackable_and_nothing_is_used_when_refused(): void
    {
        $this->voucher(['code' => 'GABUNG', 'stackable' => true]);
        $alone = $this->voucher(['code' => 'SENDIRI', 'stackable' => false]);

        $this->checkoutWithCodes(['GABUNG', 'SENDIRI'])->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_not_stackable');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, $alone->fresh()->used_count);
        // Satu voucher yang tidak boleh digabung tetap sah bila dipakai sendiri.
        $this->checkoutWithCodes(['SENDIRI'], 'key-0000000000000010')->assertCreated();
    }

    public function test_more_than_two_vouchers_are_refused_and_a_repeated_code_counts_once(): void
    {
        $this->voucher(['code' => 'SATU', 'stackable' => true]);
        $this->voucher(['code' => 'DUA', 'stackable' => true]);
        $this->voucher(['code' => 'TIGA', 'stackable' => true]);

        $this->checkoutWithCodes(['SATU', 'DUA', 'TIGA'])->assertUnprocessable();
        $this->assertSame(0, Order::count());

        $this->checkoutWithCodes(['SATU', 'satu'], 'key-0000000000000011')->assertCreated();
        $order = Order::firstOrFail();
        $this->assertNull($order->voucher_code_2);
        $this->assertSame(15000, $order->discount_total);
    }

    public function test_expiring_an_order_gives_back_both_vouchers(): void
    {
        $first = $this->voucher(['code' => 'SATU', 'stackable' => true, 'quota' => 1]);
        $second = $this->voucher(['code' => 'DUA', 'stackable' => true, 'quota' => 1]);
        $this->checkoutWithCodes(['SATU', 'DUA'])->assertCreated();
        Order::query()->update(['expires_at' => now()->subMinute()]);

        app(OrderExpiryService::class)->expireDue();

        $this->assertSame(0, $first->fresh()->used_count);
        $this->assertSame(0, $second->fresh()->used_count);
    }

    public function test_check_endpoint_previews_two_vouchers_and_lists_the_stackable_flag(): void
    {
        $this->voucher(['code' => 'POTONG50', 'value' => 50000, 'stackable' => true]);
        $this->voucher(['code' => 'SETENGAH', 'type' => 'percent', 'value' => 50, 'stackable' => true]);
        $this->voucher(['code' => 'SENDIRI']);
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2])->assertOk();

        $this->postJson('/api/v1/vouchers/check', ['codes' => ['POTONG50', 'SETENGAH']])->assertOk()
            ->assertJsonPath('data.discount', 75000)
            ->assertJsonPath('data.vouchers.1.discount', 25000);
        $this->postJson('/api/v1/vouchers/check', ['codes' => ['POTONG50', 'SENDIRI']])->assertUnprocessable()
            ->assertJsonPath('errors.voucher_code.0', 'voucher_not_stackable');

        $rows = collect($this->getJson('/api/v1/vouchers')->assertOk()->json('data.vouchers'))->keyBy('code');
        $this->assertTrue($rows['POTONG50']['stackable']);
        $this->assertFalse($rows['SENDIRI']['stackable']);
    }

    public function test_order_page_data_shows_both_codes(): void
    {
        $this->voucher(['code' => 'SATU', 'stackable' => true]);
        $this->voucher(['code' => 'DUA', 'stackable' => true]);
        $response = $this->checkoutWithCodes(['SATU', 'DUA'])->assertCreated();

        $this->getJson('/api/v1/orders/'.$response->json('data.public_ref').'?token='.$response->json('data.access_token'))
            ->assertJsonPath('data.voucher_label', 'SATU + DUA');
    }

    public function test_admin_can_mark_a_voucher_as_stackable(): void
    {
        $admin = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $admin->assignRole('store-admin');

        $this->actingAs($admin)->postJson('/api/v1/admin/vouchers', [
            'code' => 'GABUNGAN', 'type' => 'fixed', 'value' => 5000, 'min_subtotal' => 0, 'is_active' => true, 'stackable' => true,
        ])->assertCreated()->assertJsonPath('data.stackable', true);
    }
}
