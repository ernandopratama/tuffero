<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Klaim voucher ke "Voucher Saya" (D-072). Voucher yang tidak perlu klaim tetap seperti D-069.
 */
class VoucherClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $product = Product::create(['name' => 'Produk Uji', 'slug' => 'produk-uji', 'is_published' => true]);
        $variant = $product->variants()->create(['sku' => 'NAS-01', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);
        InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->value('id'), 'product_variant_id' => $variant->id, 'quantity' => 20]);
    }

    private function voucher(array $attributes = []): Voucher
    {
        return Voucher::create([
            'code' => 'KLAIM10', 'type' => 'fixed', 'value' => 10000, 'min_subtotal' => 0, 'is_active' => true,
            'requires_claim' => true, 'per_customer_limit' => 1, ...$attributes,
        ]);
    }

    private function member(): User
    {
        return User::factory()->create();
    }

    private function codes($response, string $key = 'data.vouchers'): array
    {
        return collect($response->json($key))->pluck('code')->all();
    }

    private function checkout(string $code, string $key = 'key-0000000000000001', string $phone = '0812-3456-7890')
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2])->assertOk();

        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi', 'customer_email' => 'b@example.com', 'customer_phone' => $phone,
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt', 'service' => 'ez', 'voucher_code' => $code,
        ]);
    }

    public function test_claim_page_lists_only_listed_claimable_vouchers_that_are_current(): void
    {
        $this->voucher();
        $this->voucher(['code' => 'TANPAKLAIM', 'requires_claim' => false]);
        $this->voucher(['code' => 'RAHASIA', 'is_listed' => false]);
        $this->voucher(['code' => 'TAMUAJA', 'audience' => 'guest', 'requires_claim' => false]);
        $this->voucher(['code' => 'LEWAT', 'ends_at' => now()->subMinute()]);
        $this->voucher(['code' => 'MATI', 'is_active' => false]);

        $guest = $this->getJson('/api/v1/vouchers/claimable')->assertOk();
        $this->assertSame(['KLAIM10'], $this->codes($guest));
        $this->assertFalse($guest->json('data.vouchers.0.claimed'));
    }

    public function test_guest_cannot_claim_and_staff_is_not_a_customer(): void
    {
        $this->voucher();

        $this->postJson('/api/v1/account/vouchers/KLAIM10/claim')->assertUnauthorized();

        $staff = User::factory()->create();
        $staff->assignRole('store-admin');
        $this->actingAs($staff)->postJson('/api/v1/account/vouchers/KLAIM10/claim')->assertForbidden();
        $this->getJson('/api/v1/account/vouchers')->assertForbidden();
    }

    public function test_member_claims_once_and_the_voucher_moves_to_the_wallet(): void
    {
        $voucher = $this->voucher();
        $user = $this->member();

        $this->actingAs($user)->postJson('/api/v1/account/vouchers/klaim10/claim')->assertOk()->assertJsonPath('data.code', 'KLAIM10');
        $this->postJson('/api/v1/account/vouchers/KLAIM10/claim')->assertOk();

        $this->assertSame(1, VoucherClaim::where('voucher_id', $voucher->id)->count());
        $this->assertTrue($this->getJson('/api/v1/vouchers/claimable')->json('data.vouchers.0.claimed'));
        $this->getJson('/api/v1/account/vouchers')->assertOk()->assertJsonPath('data.items.0.code', 'KLAIM10')->assertJsonPath('data.items.0.status', 'available');
    }

    public function test_claim_rejects_unknown_non_claimable_inactive_expired_exhausted_and_guest_only_vouchers(): void
    {
        $this->voucher(['code' => 'TANPAKLAIM', 'requires_claim' => false]);
        $this->voucher(['code' => 'MATI', 'is_active' => false]);
        $this->voucher(['code' => 'LEWAT', 'ends_at' => now()->subMinute()]);
        $this->voucher(['code' => 'HABIS', 'quota' => 1])->forceFill(['used_count' => 1])->save();
        $this->voucher(['code' => 'TAMUAJA', 'audience' => 'guest']);
        $this->actingAs($this->member());

        foreach (['TIDAKADA' => 'voucher_invalid', 'TANPAKLAIM' => 'voucher_invalid', 'MATI' => 'voucher_invalid', 'LEWAT' => 'voucher_invalid', 'HABIS' => 'voucher_exhausted', 'TAMUAJA' => 'voucher_guest_only'] as $code => $reason) {
            $this->postJson("/api/v1/account/vouchers/{$code}/claim")->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', $reason);
        }
        $this->assertSame(0, VoucherClaim::count());
    }

    public function test_checkout_picker_shows_only_claimed_claimable_vouchers(): void
    {
        $this->voucher();
        $this->voucher(['code' => 'BELUMKLAIM']);
        $this->voucher(['code' => 'UMUM', 'requires_claim' => false]);
        $user = $this->member();
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2])->assertOk();

        $this->assertSame(['UMUM'], $this->codes($this->actingAs($user)->getJson('/api/v1/vouchers')));

        $this->postJson('/api/v1/account/vouchers/KLAIM10/claim')->assertOk();
        $this->assertSame(['KLAIM10', 'UMUM'], collect($this->codes($this->getJson('/api/v1/vouchers')))->sort()->values()->all());
    }

    public function test_typing_the_code_claims_it_for_a_member_and_a_guest_is_told_to_sign_in(): void
    {
        $voucher = $this->voucher(['is_listed' => false]);
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2])->assertOk();

        $this->postJson('/api/v1/vouchers/check', ['code' => 'KLAIM10'])->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_not_claimed');
        $this->assertSame(0, VoucherClaim::count());

        $user = $this->member();
        $this->actingAs($user)->postJson('/api/v1/vouchers/check', ['code' => 'KLAIM10'])->assertOk()->assertJsonPath('data.discount', 10000);
        $this->assertSame(1, VoucherClaim::where('voucher_id', $voucher->id)->where('user_id', $user->id)->count());
    }

    public function test_checkout_with_a_claimable_voucher_works_for_a_member_and_is_refused_for_a_guest(): void
    {
        $this->voucher();

        $this->checkout('KLAIM10')->assertUnprocessable()->assertJsonPath('errors.voucher_code.0', 'voucher_not_claimed');
        $this->assertSame(0, Order::count());

        $this->actingAs($this->member());
        $this->checkout('KLAIM10', 'key-0000000000000002')->assertCreated();
        $this->assertSame(10000, Order::firstOrFail()->discount_total);
    }

    public function test_wallet_statuses_and_order(): void
    {
        $user = $this->member();
        $this->actingAs($user);
        $this->voucher(['code' => 'AKTIF']);
        $this->voucher(['code' => 'PAKAI', 'per_customer_limit' => 1]);
        $this->voucher(['code' => 'LEWAT']);
        foreach (['LEWAT', 'PAKAI', 'AKTIF'] as $code) {
            $this->postJson("/api/v1/account/vouchers/{$code}/claim")->assertOk();
        }
        $this->checkout('PAKAI')->assertCreated();
        Voucher::where('code', 'LEWAT')->update(['ends_at' => now()->subMinute()]);

        $items = $this->getJson('/api/v1/account/vouchers')->assertOk()->json('data.items');

        $this->assertSame(['AKTIF' => 'available', 'PAKAI' => 'used', 'LEWAT' => 'expired'], collect($items)->pluck('status', 'code')->all());
        $this->assertSame(['AKTIF', 'PAKAI', 'LEWAT'], collect($items)->pluck('code')->all());
    }

    public function test_voucher_pages_render_and_the_wallet_page_needs_a_customer_login(): void
    {
        $this->withoutVite();

        $this->get('/voucher')->assertOk()->assertSee('Voucher | Tuffero Bakery', false);
        $this->get('/voucher?lang=en')->assertOk()->assertSee('Vouchers | Tuffero Bakery', false);
        $this->get('/akun/voucher')->assertRedirect();

        $this->actingAs($this->member())->get('/akun/voucher')->assertOk()->assertSee('Voucher Saya');
        $this->get('/akun')->assertOk()->assertSee('/akun/voucher', false);
    }

    public function test_a_wallet_only_shows_own_claims(): void
    {
        $this->voucher();
        $other = $this->member();
        $this->actingAs($other)->postJson('/api/v1/account/vouchers/KLAIM10/claim')->assertOk();

        $this->actingAs($this->member())->getJson('/api/v1/account/vouchers')->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_admin_cannot_make_a_claimable_voucher_guest_only_and_sees_the_claim_count(): void
    {
        $admin = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $admin->assignRole('store-admin');
        $body = ['code' => 'ADMINKLAIM', 'type' => 'fixed', 'value' => 5000, 'min_subtotal' => 0, 'is_active' => true, 'requires_claim' => true];

        $this->actingAs($admin)->postJson('/api/v1/admin/vouchers', [...$body, 'audience' => 'guest'])->assertUnprocessable()->assertJsonValidationErrors('audience');
        $this->postJson('/api/v1/admin/vouchers', [...$body, 'audience' => 'member'])->assertCreated();

        $this->actingAs($this->member())->postJson('/api/v1/account/vouchers/ADMINKLAIM/claim')->assertOk();
        $this->actingAs($admin)->getJson('/api/v1/admin/vouchers')->assertJsonPath('data.items.0.claims_count', 1)->assertJsonPath('data.items.0.requires_claim', true);
    }
}
