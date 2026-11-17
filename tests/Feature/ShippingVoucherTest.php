<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use App\Models\VoucherRedemption;
use App\Models\Warehouse;
use App\Services\OrderExpiryService;
use App\Services\VoucherService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Voucher gratis ongkir (D-126). Keranjang uji: 2 x Rp50.000 = Rp100.000, ongkir uji Rp12.000, alamat Jawa Timur.
 */
class ShippingVoucherTest extends TestCase
{
    use RefreshDatabase;

    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $warehouse = Warehouse::where('code', 'MLG')->firstOrFail();
        $product = Product::create(['name' => 'Produk Uji', 'slug' => 'produk-uji', 'is_published' => true]);
        $variant = $product->variants()->create(['sku' => 'NAS-01', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);
        InventoryStock::create(['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'quantity' => 50]);
    }

    private function shippingVoucher(array $attributes = []): Voucher
    {
        return Voucher::create([
            'code' => 'ONGKIR', 'type' => 'fixed', 'kind' => 'shipping', 'audience' => 'member', 'value' => 25000, 'min_subtotal' => 0,
            'is_active' => true, 'per_customer_limit' => null, ...$attributes,
        ]);
    }

    private function regularVoucher(string $code, array $attributes = []): Voucher
    {
        return Voucher::create([
            'code' => $code, 'type' => 'fixed', 'value' => 10000, 'min_subtotal' => 0, 'is_active' => true, 'per_customer_limit' => null, ...$attributes,
        ]);
    }

    private function member(string $phone = '6281234567890', bool $verified = true): User
    {
        $user = User::factory()->create();
        $user->forceFill(['phone' => $phone, 'phone_verified_at' => $verified ? now() : null])->save();
        $this->actingAs($user);

        return $user;
    }

    /** @param list<string> $codes */
    private function checkout(array $codes, string $province = 'Jawa Timur')
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2])->assertOk();

        return $this->withHeader('Idempotency-Key', 'key-'.str_pad((string) ++$this->keys, 16, '0', STR_PAD_LEFT))->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi Uji',
            'customer_email' => 'budi@example.com',
            'customer_phone' => '0812-9999-0000',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => $province, 'postal_code' => '65111'],
            'courier' => 'jnt',
            'service' => 'ez',
            'voucher_codes' => $codes,
        ]);
    }

    public function test_shipping_discount_is_capped_by_the_fee_and_kept_apart_from_the_full_fee(): void
    {
        $this->shippingVoucher();
        $this->member();

        $this->checkout(['ongkir'])->assertCreated();

        $order = Order::firstOrFail();
        $this->assertSame(12000, $order->shipping_fee);
        $this->assertSame(12000, $order->shipping_discount);
        $this->assertSame(0, $order->discount_total);
        $this->assertSame(100000, $order->total);
        $this->assertSame('ONGKIR', $order->shipping_voucher_code);
        $this->assertNull($order->voucher_code);
    }

    public function test_partial_shipping_discount_and_browser_totals_are_ignored(): void
    {
        $this->shippingVoucher(['value' => 5000]);
        $this->member();

        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2])->assertOk();
        $this->withHeader('Idempotency-Key', 'key-0000000000009999')->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi Uji', 'customer_email' => 'budi@example.com', 'customer_phone' => '0812-9999-0000',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt', 'service' => 'ez', 'voucher_codes' => ['ONGKIR'], 'shipping_discount' => 99999, 'total' => 1,
        ])->assertCreated();

        $order = Order::firstOrFail();
        $this->assertSame(5000, $order->shipping_discount);
        $this->assertSame(107000, $order->total);
    }

    public function test_guests_and_unverified_numbers_cannot_use_it(): void
    {
        $this->shippingVoucher(['audience' => 'all']);

        $this->checkout(['ONGKIR'])->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_member_only');

        $this->member(verified: false);
        $this->checkout(['ONGKIR'])->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_phone_unverified');
    }

    public function test_one_verified_number_uses_it_once_even_across_accounts(): void
    {
        $voucher = $this->shippingVoucher(['per_customer_limit' => 1]);
        $first = $this->member('6281111111111');
        $this->checkout(['ONGKIR'])->assertCreated();
        $this->assertSame('6281111111111', VoucherRedemption::firstOrFail()->customer_phone);

        // Akun lain yang kemudian memverifikasi nomor yang sama tetap ditolak.
        $first->forceFill(['phone' => null, 'phone_verified_at' => null])->save();
        $this->member('6281111111111');
        $this->checkout(['ONGKIR'])->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_used');
        $this->assertSame(1, $voucher->fresh()->used_count);
    }

    public function test_monthly_limit_resets_on_the_first_of_the_month(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 20));
        $this->shippingVoucher(['per_customer_limit' => 1, 'limit_period' => 'month']);
        $this->member();

        $this->checkout(['ONGKIR'])->assertCreated();
        $this->checkout(['ONGKIR'])->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_used');

        $this->travelTo(now()->setDate(2026, 12, 1)->setTime(0, 5));
        $this->checkout(['ONGKIR'])->assertCreated();
    }

    public function test_area_is_checked_against_the_shipping_province(): void
    {
        $this->shippingVoucher(['provinces' => ['Jawa Timur', 'Bali']]);
        $this->member();

        $this->checkout(['ONGKIR'], 'DKI Jakarta')->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_area');
        $this->checkout(['ONGKIR'], '  jawa   timur ')->assertCreated();
    }

    public function test_budget_stops_the_voucher_when_the_rest_is_smaller_than_the_discount(): void
    {
        $voucher = $this->shippingVoucher(['budget' => 20000]);
        $this->member('6281000000001');
        $this->checkout(['ONGKIR'])->assertCreated(); // terpakai 12.000, sisa 8.000

        $this->member('6281000000002');
        $this->checkout(['ONGKIR'])->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_exhausted');
        $this->assertSame(12000, app(VoucherService::class)->spent($voucher));
    }

    public function test_combinations_follow_d126(): void
    {
        $this->shippingVoucher();
        $this->shippingVoucher(['code' => 'ONGKIR2']);
        $this->regularVoucher('SOLO'); // tidak stackable
        $this->regularVoucher('GABUNG1', ['stackable' => true]);
        $this->regularVoucher('GABUNG2', ['stackable' => true]);
        $this->member();

        $this->checkout(['ONGKIR', 'ONGKIR2'])->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_shipping_limit');
        $this->checkout(['SOLO', 'GABUNG1'])->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_not_stackable');
        $this->checkout(['ONGKIR', 'GABUNG1', 'GABUNG2'])->assertUnprocessable()->assertJsonValidationErrors('voucher_codes');

        // Voucher biasa yang tidak stackable boleh dipasangkan dengan gratis ongkir.
        $this->checkout(['SOLO', 'ONGKIR'])->assertCreated();
        $order = Order::latest('id')->firstOrFail();
        // Checkout yang ditolak tidak mengosongkan keranjang, jadi ongkir di sini lebih besar; potongan tetap maksimal Rp25.000.
        $this->assertSame(10000, $order->discount_total);
        $this->assertSame(min(25000, $order->shipping_fee), $order->shipping_discount);
        $this->assertSame($order->subtotal - 10000 + $order->shipping_fee - $order->shipping_discount, $order->total);
        $this->assertSame('SOLO', $order->voucher_code);
        $this->assertSame('ONGKIR', $order->shipping_voucher_code);
    }

    public function test_check_endpoint_previews_the_shipping_discount_separately(): void
    {
        $this->shippingVoucher();
        $this->regularVoucher('SOLO');
        $this->member();
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2])->assertOk();

        $this->postJson('/api/v1/vouchers/check', ['codes' => ['SOLO', 'ONGKIR'], 'shipping_fee' => 9000])
            ->assertOk()
            ->assertJsonPath('data.discount', 10000)
            ->assertJsonPath('data.shipping_discount', 9000)
            ->assertJsonPath('data.vouchers.1.kind', 'shipping');
    }

    public function test_signup_grant_is_claimed_for_new_members_and_expires_after_its_days(): void
    {
        $voucher = $this->shippingVoucher(['signup_grant' => true, 'requires_claim' => true, 'is_listed' => false, 'claim_valid_days' => 30, 'per_customer_limit' => 1]);
        $user = $this->member();

        // Member lama tidak bisa mengklaim sendiri.
        $this->postJson('/api/v1/account/vouchers/ONGKIR/claim')->assertUnprocessable();
        $this->checkout(['ONGKIR'])->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_invalid');

        app(VoucherService::class)->grantSignup($user);
        $this->assertNotNull(VoucherClaim::where('voucher_id', $voucher->id)->where('user_id', $user->id)->value('expires_at'));

        $this->travel(31)->days();
        $this->checkout(['ONGKIR'])->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_invalid');
        $this->assertSame('expired', $this->getJson('/api/v1/account/vouchers')->json('data.items.0.status'));
    }

    public function test_cancelled_order_gives_the_voucher_back(): void
    {
        $voucher = $this->shippingVoucher(['per_customer_limit' => 1, 'budget' => 12000]);
        $this->member();
        $this->checkout(['ONGKIR'])->assertCreated();

        Order::firstOrFail()->forceFill(['expires_at' => now()->subMinute()])->save();
        app(OrderExpiryService::class)->expireDue();

        $this->assertSame(0, $voucher->fresh()->used_count);
        $this->assertSame(0, app(VoucherService::class)->spent($voucher));
        $this->checkout(['ONGKIR'])->assertCreated();
    }

    public function test_admin_rules_for_shipping_vouchers(): void
    {
        $admin = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $admin->assignRole('store-admin');
        $this->actingAs($admin);
        $base = ['code' => 'ONGKIRBARU', 'kind' => 'shipping', 'type' => 'percent', 'audience' => 'guest', 'min_subtotal' => 500000, 'is_active' => true];

        $this->postJson('/api/v1/admin/vouchers', [...$base, 'value' => 30000])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->postJson('/api/v1/admin/vouchers', [...$base, 'value' => 999])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->postJson('/api/v1/admin/vouchers', [...$base, 'value' => 25000, 'signup_grant' => true])->assertUnprocessable()->assertJsonValidationErrors('claim_valid_days');

        $this->postJson('/api/v1/admin/vouchers', [...$base, 'value' => 25000, 'provinces' => ['Jawa Timur'], 'budget' => 2500000, 'limit_period' => 'month', 'per_customer_limit' => 1])
            ->assertCreated();
        $voucher = Voucher::where('code', 'ONGKIRBARU')->firstOrFail();
        $this->assertSame(['fixed', 'member'], [$voucher->type, $voucher->audience]);
        $this->assertSame(['Jawa Timur'], $voucher->provinces);
        $this->assertSame(0, $this->getJson('/api/v1/admin/vouchers')->json('data.items.0.spent'));
    }
}
