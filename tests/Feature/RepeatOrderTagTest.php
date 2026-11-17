<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderStatusService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tag repeat order (D-121 butir 6, D-135): pesanan selesai ke-N per nomor WhatsApp, termasuk tamu, tanpa pesanan yang direfund. */
class RepeatOrderTagTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '081234567890';

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $product = Product::create(['name' => 'Nastar', 'slug' => 'nastar', 'is_published' => true]);
        $variant = $product->variants()->create(['sku' => 'NAS-01', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);
        InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->value('id'), 'product_variant_id' => $variant->id, 'quantity' => 500]);
        Setting::where('key', 'antifraud.max_orders_per_day')->update(['value' => '50']);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    /** Pesanan tamu lewat checkout; selesai bila $completed. */
    private function order(string $phone = self::PHONE, bool $completed = true): Order
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 1])->assertOk();
        $ref = $this->withHeader('Idempotency-Key', 'key-'.str()->random(20))->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi Uji', 'customer_email' => 'budi@example.com', 'customer_phone' => $phone,
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt', 'service' => 'ez',
        ])->assertCreated()->json('data.public_ref');
        $order = Order::where('public_ref', $ref)->firstOrFail();

        if ($completed) {
            $order = app(OrderStatusService::class)->markPaid($order);
            $order->forceFill(['status' => Order::COMPLETED])->save();
        }

        return $order->refresh();
    }

    private function refund(Order $order, string $status): void
    {
        $this->seq++;
        Refund::create([
            'public_ref' => (string) str()->uuid(), 'refund_number' => 'RF2611-'.str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT), 'order_id' => $order->id,
            'idempotency_key' => 'idem-'.$this->seq, 'requester_email' => $order->customer_email, 'requester_phone' => $order->customer_phone,
            'contact_preference' => 'whatsapp', 'reason_type' => Refund::RETURN_REASON, 'description' => 'Uji', 'requested_amount' => 50000,
            'evidence_status' => 'received', 'terms_version' => '1', 'terms_accepted_at' => now(), 'status' => $status, 'due_at' => now()->addDays(7),
        ]);
    }

    /** @return array<string, mixed> */
    private function detail(Order $order): array
    {
        return $this->actingAs($this->staff('store-admin'))->getJson("/api/v1/admin/orders/{$order->id}")->assertOk()->json('data');
    }

    public function test_the_fifth_completed_order_tags_the_phone_in_order_list_and_detail(): void
    {
        $orders = collect(range(1, 4))->map(fn () => $this->order());
        $this->assertFalse($this->detail($orders->last())['repeat_order']);
        $this->assertSame(4, $this->detail($orders->last())['completed_orders']);

        $fifth = $this->order();
        $this->assertTrue($this->detail($fifth)['repeat_order']);

        $row = collect($this->actingAs($this->staff('store-admin'))->getJson('/api/v1/admin/orders')->assertOk()->json('data.items'))
            ->firstWhere('id', $fifth->id);
        $this->assertTrue($row['repeat_order']);
        $this->assertSame(5, $row['completed_orders']);
    }

    public function test_unfinished_and_refunded_orders_do_not_count(): void
    {
        collect(range(1, 3))->each(fn () => $this->order());
        $this->order(completed: false);
        $this->refund($this->order(), Refund::APPROVED);
        $this->refund($this->order(), Refund::REFUNDED);
        $last = $this->order();
        // Pengajuan yang belum disetujui tidak mengurangi hitungan.
        $this->refund($last, Refund::UNDER_REVIEW);

        $this->assertSame(4, $this->detail($last)['completed_orders']);
        $this->assertFalse($this->detail($last)['repeat_order']);
    }

    public function test_counts_are_per_phone_number(): void
    {
        collect(range(1, 4))->each(fn () => $this->order());
        $other = $this->order('081299998888');

        $this->assertSame(1, $this->detail($other)['completed_orders']);
        $this->assertFalse($this->detail($other)['repeat_order']);
    }

    public function test_guest_orders_count_for_the_account_with_the_same_phone(): void
    {
        collect(range(1, 5))->each(fn () => $this->order());
        // Nomor akun disimpan sudah dinormalkan (+62), sama seperti nomor pesanan.
        $customer = User::factory()->create(['phone' => '+6281234567890']);
        $admin = $this->staff('store-admin');

        $row = collect($this->actingAs($admin)->getJson('/api/v1/admin/customers')->assertOk()->json('data.items'))->firstWhere('id', $customer->id);
        $this->assertTrue($row['repeat_order']);
        $this->actingAs($admin)->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk()
            ->assertJsonPath('data.repeat_order', true)->assertJsonPath('data.completed_orders', 5);
    }

    public function test_the_threshold_follows_the_setting(): void
    {
        Setting::where('key', 'loyalty.repeat_order_count')->update(['value' => '3']);
        collect(range(1, 2))->each(fn () => $this->order());
        $third = $this->order();

        $this->assertTrue($this->detail($third)['repeat_order']);
    }

    public function test_only_super_admin_changes_the_threshold(): void
    {
        $this->actingAs($this->staff('store-admin'))->patchJson('/api/v1/admin/settings/loyalty.repeat_order_count', ['value' => 3])->assertForbidden();
        $superAdmin = $this->staff('super-admin');
        $this->actingAs($superAdmin)->patchJson('/api/v1/admin/settings/loyalty.repeat_order_count', ['value' => 1])->assertJsonValidationErrors('value');
        $this->actingAs($superAdmin)->patchJson('/api/v1/admin/settings/loyalty.repeat_order_count', ['value' => 6])->assertOk();

        $this->assertSame('6', Setting::where('key', 'loyalty.repeat_order_count')->value('value'));
    }
}
