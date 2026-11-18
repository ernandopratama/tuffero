<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CustomerBlock;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CustomerBlockService;
use App\Services\OrderStatusService;
use App\Services\RefundService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Blokir otomatis retur berturut-turut (D-122) dan daftar blokir: pemicu, penegakan di checkout, pencabutan, hak akses. */
class CustomerBlockTest extends TestCase
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
        Setting::where('key', 'antifraud.return_block_enabled')->update(['value' => '1']);
        Setting::where('key', 'antifraud.max_orders_per_day')->update(['value' => '50']);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, string> */
    private function address(string $street = 'Jl. Uji 1'): array
    {
        return ['street' => $street, 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'];
    }

    /** @return array<string, mixed> */
    private function payload(string $phone, array $address): array
    {
        return [
            'customer_name' => 'Budi Uji', 'customer_email' => 'budi@example.com', 'customer_phone' => $phone,
            'address' => $address, 'courier' => 'jnt', 'service' => 'ez',
        ];
    }

    private function checkout(string $phone = self::PHONE, ?array $address = null): TestResponse
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 1])->assertOk();

        return $this->withHeader('Idempotency-Key', 'key-'.str()->random(20))->postJson('/api/v1/checkout', $this->payload($phone, $address ?? $this->address()));
    }

    /** Pesanan yang sudah diterima (selesai). */
    private function receivedOrder(string $phone = self::PHONE, ?array $address = null): Order
    {
        $ref = $this->checkout($phone, $address)->assertCreated()->json('data.public_ref');
        $order = app(OrderStatusService::class)->markPaid(Order::where('public_ref', $ref)->firstOrFail());
        $order->forceFill(['status' => Order::COMPLETED])->save();

        return $order->refresh();
    }

    private function refundFor(Order $order, string $reason = Refund::RETURN_REASON, string $status = Refund::UNDER_REVIEW): Refund
    {
        $this->seq++;

        return Refund::create([
            'public_ref' => (string) str()->uuid(), 'refund_number' => 'RF2611-'.str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT), 'order_id' => $order->id,
            'idempotency_key' => 'idem-'.$this->seq, 'requester_email' => $order->customer_email, 'requester_phone' => $order->customer_phone,
            'contact_preference' => 'whatsapp', 'reason_type' => $reason, 'description' => 'Uji', 'requested_amount' => 50000,
            'evidence_status' => 'received', 'terms_version' => '1', 'terms_accepted_at' => now(), 'status' => $status, 'due_at' => now()->addDays(7),
        ]);
    }

    private function approve(Refund $refund): void
    {
        app(RefundService::class)->approve($refund, 50000, false, null, $this->staff('owner'));
    }

    /** @return list<Order> */
    private function returnedOrders(int $count, string $phone = self::PHONE, ?array $address = null): array
    {
        $orders = [];
        for ($i = 0; $i < $count; $i++) {
            $order = $this->receivedOrder($phone, $address);
            $this->approve($this->refundFor($order));
            $orders[] = $order;
        }

        return $orders;
    }

    // ---------- pemicu ----------

    public function test_three_returned_orders_in_a_row_block_the_customer_and_notify_staff(): void
    {
        $storeAdmin = $this->staff('store-admin');
        $owner = $this->staff('owner');

        $this->returnedOrders(2);
        $this->assertSame(0, CustomerBlock::count(), 'Dua retur belum cukup.');

        $third = $this->receivedOrder();
        $this->approve($this->refundFor($third));

        $block = CustomerBlock::firstOrFail();
        $this->assertSame('auto', $block->source);
        $this->assertSame('0812****7890', $block->phone_masked);
        $this->assertCount(3, $block->order_ids);
        $this->assertTrue(AuditLog::where('action', 'customer.auto_blocked')->exists());
        $blocked = fn (User $user) => collect($user->fresh()->notifications)->filter(fn ($n) => str_contains($n->data['title'], 'diblokir otomatis'))->count();
        $this->assertSame(1, $blocked($storeAdmin));
        $this->assertSame(1, $blocked($owner));
        $this->assertStringNotContainsString(self::PHONE, json_encode($block->toArray()), 'Nomor tidak disimpan polos di blokir.');
    }

    public function test_the_rule_is_off_by_default(): void
    {
        Setting::where('key', 'antifraud.return_block_enabled')->update(['value' => '0']);

        $this->returnedOrders(3);

        $this->assertSame(0, CustomerBlock::count());
        $this->checkout()->assertCreated();
    }

    public function test_an_order_without_a_return_breaks_the_streak(): void
    {
        $this->returnedOrders(2);
        $this->receivedOrder(); // diterima, tidak diretur
        $this->returnedOrders(2);

        $this->assertSame(0, CustomerBlock::count(), 'Tiga pesanan terakhir tidak semuanya diretur.');
    }

    public function test_only_approved_returns_of_unopened_products_count(): void
    {
        $first = $this->returnedOrders(2);
        $third = $this->receivedOrder();

        $this->approve($this->refundFor($third, 'damaged'));
        $this->assertSame(0, CustomerBlock::count(), 'Klaim barang rusak tidak dihitung.');

        $this->refundFor($third, Refund::RETURN_REASON, Refund::SUBMITTED);
        $this->assertSame(0, CustomerBlock::count(), 'Pengajuan yang belum disetujui tidak dihitung.');

        $this->refundFor($third, Refund::RETURN_REASON, Refund::REJECTED);
        $this->assertSame(0, CustomerBlock::count(), 'Pengajuan yang ditolak tidak dihitung.');
        $this->assertCount(2, $first);
    }

    public function test_the_streak_is_per_phone_number(): void
    {
        $this->returnedOrders(2);
        $this->returnedOrders(1, '081299990000');

        $this->assertSame(0, CustomerBlock::count());
    }

    public function test_evaluating_twice_keeps_one_active_block(): void
    {
        $orders = $this->returnedOrders(3);
        $refund = Refund::where('order_id', $orders[2]->id)->firstOrFail();

        app(CustomerBlockService::class)->evaluate($refund->load('order'));
        app(CustomerBlockService::class)->evaluate($refund->load('order'));

        $this->assertSame(1, CustomerBlock::count());
    }

    // ---------- penegakan ----------

    public function test_blocked_phone_or_address_cannot_place_a_new_order_but_name_and_ip_do_not_block(): void
    {
        $this->returnedOrders(3);

        $this->checkout()->assertStatus(422)->assertJsonValidationErrors(['customer_phone']);
        $this->checkout('081255550000', $this->address('Jl. Lain 9'))->assertCreated(); // nomor, alamat, dan nama (Budi Uji) lain; IP sama

        // Nomor baru di alamat yang sama (alamat diblokir) ditolak.
        $response = $this->checkout('081266660000', $this->address());
        $response->assertStatus(422);
        $this->assertSame('customer_blocked', $response->json('errors.customer_phone.0'));
    }

    public function test_checkout_records_the_buyer_ip_as_evidence(): void
    {
        $order = $this->receivedOrder('081277770000');

        $this->assertNotNull($order->ip_address);
    }

    public function test_switching_the_rule_off_stops_new_auto_blocks_but_active_blocks_still_apply(): void
    {
        $this->returnedOrders(3);
        Setting::where('key', 'antifraud.return_block_enabled')->update(['value' => '0']);

        $this->checkout()->assertStatus(422); // blokir yang sudah aktif tetap berlaku; jalan keluarnya mencabut

        $this->returnedOrders(3, '081288880000', $this->address('Jl. Mawar 7'));
        $this->assertSame(1, CustomerBlock::count(), 'Saklar mati: tidak ada blokir otomatis baru.');
    }

    public function test_a_manual_block_works_even_while_the_auto_rule_is_off(): void
    {
        Setting::where('key', 'antifraud.return_block_enabled')->update(['value' => '0']);
        $this->actingAs($this->staff('store-admin'))->postJson('/api/v1/admin/customer-blocks', ['phone' => self::PHONE, 'note' => 'Penipuan, laporan CS'])->assertCreated();
        $this->app['auth']->forgetGuards();

        $this->checkout()->assertStatus(422);
    }

    public function test_a_manual_address_block_matches_on_street_and_postal_code_only(): void
    {
        $this->actingAs($this->staff('store-admin'))->postJson('/api/v1/admin/customer-blocks', [
            'phone' => '081200001111', 'address' => ['street' => 'JL. Uji  1', 'postal_code' => '65111'], 'note' => 'Alamat penipuan',
        ])->assertCreated();
        $this->app['auth']->forgetGuards();

        $this->checkout('081255550000', $this->address('jl uji 1'))->assertStatus(422);
        $this->checkout('081255550000', $this->address('Jl. Lain 9'))->assertCreated();
    }

    // ---------- pencabutan dan hak akses ----------

    public function test_store_admin_releases_a_block_with_a_note_and_the_customer_can_buy_again(): void
    {
        $this->returnedOrders(3);
        $block = CustomerBlock::firstOrFail();
        $admin = $this->staff('store-admin');

        $this->actingAs($admin)->postJson("/api/v1/admin/customer-blocks/{$block->public_id}/release", [])->assertJsonValidationErrors('note');
        $this->actingAs($admin)->postJson("/api/v1/admin/customer-blocks/{$block->public_id}/release", ['note' => 'Salah paham, sudah dihubungi'])->assertOk();

        $block->refresh();
        $this->assertNotNull($block->released_at);
        $this->assertSame($admin->id, $block->released_by_user_id);
        $this->assertTrue(AuditLog::where('action', 'customer.block_released')->exists());
        $this->actingAs($admin)->postJson("/api/v1/admin/customer-blocks/{$block->public_id}/release", ['note' => 'Sekali lagi'])->assertJsonValidationErrors('block');

        $this->app['auth']->forgetGuards();
        $this->checkout()->assertCreated();
    }

    public function test_a_released_customer_can_be_blocked_again_by_a_new_streak(): void
    {
        $this->returnedOrders(3);
        app(CustomerBlockService::class)->release(CustomerBlock::firstOrFail(), 'Dicabut', $this->staff('store-admin'));

        $this->returnedOrders(3);

        $this->assertSame(2, CustomerBlock::count());
        $this->assertSame(1, CustomerBlock::active()->count());
    }

    public function test_visibility_and_manage_permissions_per_role(): void
    {
        $this->returnedOrders(3);
        $block = CustomerBlock::firstOrFail();

        foreach (['super-admin', 'store-admin', 'owner', 'customer-service'] as $role) {
            $this->actingAs($this->staff($role))->getJson('/api/v1/admin/customer-blocks')->assertOk()->assertJsonPath('data.items.0.phone', '0812****7890');
        }
        foreach (['warehouse', 'marketing'] as $role) {
            $this->actingAs($this->staff($role))->getJson('/api/v1/admin/customer-blocks')->assertForbidden();
        }
        foreach (['owner', 'customer-service', 'warehouse', 'marketing'] as $role) {
            $user = $this->staff($role);
            $this->actingAs($user)->postJson("/api/v1/admin/customer-blocks/{$block->public_id}/release", ['note' => 'Coba cabut'])->assertForbidden();
            $this->actingAs($user)->postJson('/api/v1/admin/customer-blocks', ['phone' => '081200001111', 'note' => 'Coba tambah'])->assertForbidden();
        }
        $this->assertTrue($block->refresh()->isActive());
    }

    public function test_staff_without_mfa_cannot_change_blocks(): void
    {
        $user = User::factory()->create();
        $user->assignRole('store-admin');

        $this->actingAs($user)->postJson('/api/v1/admin/customer-blocks', ['phone' => '081200001111', 'note' => 'Uji tanpa MFA'])->assertForbidden();
    }

    // ---------- blokir manual ----------

    public function test_store_admin_adds_edits_and_the_manual_block_is_enforced_with_a_normalised_number(): void
    {
        $admin = $this->staff('store-admin');

        $created = $this->actingAs($admin)->postJson('/api/v1/admin/customer-blocks', ['phone' => '0812 3456 7890', 'customer_name' => 'Pembeli Nakal', 'note' => 'Penipuan, laporan CS'])->assertCreated();
        $created->assertJsonPath('data.source', 'manual')->assertJsonPath('data.phone', '0812****7890');
        $id = $created->json('data.id');

        $this->actingAs($admin)->postJson('/api/v1/admin/customer-blocks', ['phone' => '+6281234567890', 'note' => 'Duplikat nomor sama'])->assertJsonValidationErrors('phone');
        $this->actingAs($admin)->postJson('/api/v1/admin/customer-blocks', ['phone' => '12345', 'note' => 'Nomor tidak valid'])->assertJsonValidationErrors('phone');
        $this->actingAs($admin)->postJson('/api/v1/admin/customer-blocks', ['phone' => '081200001111'])->assertJsonValidationErrors('note');

        $this->actingAs($admin)->patchJson("/api/v1/admin/customer-blocks/{$id}", ['note' => 'Catatan diperbarui'])->assertOk()->assertJsonPath('data.note', 'Catatan diperbarui');
        $this->assertTrue(AuditLog::where('action', 'customer.block_created')->exists());
        $this->assertTrue(AuditLog::where('action', 'customer.block_note_updated')->exists());

        $this->app['auth']->forgetGuards();
        $this->checkout()->assertStatus(422);
    }

    public function test_list_filters_by_status_and_search(): void
    {
        $this->returnedOrders(3);
        $admin = $this->staff('store-admin');
        $this->actingAs($admin)->postJson('/api/v1/admin/customer-blocks', ['phone' => '081200001111', 'customer_name' => 'Ani', 'note' => 'Uji daftar'])->assertCreated();
        app(CustomerBlockService::class)->release(CustomerBlock::where('source', 'manual')->firstOrFail(), 'Dicabut', $admin);

        $this->actingAs($admin)->getJson('/api/v1/admin/customer-blocks')->assertJsonPath('data.meta.total', 2);
        $this->actingAs($admin)->getJson('/api/v1/admin/customer-blocks?status=active')->assertJsonPath('data.meta.total', 1);
        $this->actingAs($admin)->getJson('/api/v1/admin/customer-blocks?status=released')->assertJsonPath('data.meta.total', 1);
        $this->actingAs($admin)->getJson('/api/v1/admin/customer-blocks?search=Ani')->assertJsonPath('data.meta.total', 1);
    }
}
