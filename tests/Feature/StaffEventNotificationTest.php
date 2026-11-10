<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CancellationService;
use App\Services\InventoryService;
use App\Services\OrderStatusService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

/**
 * Peristiwa operasional yang memunculkan notifikasi di lonceng, per peran penerima.
 */
class StaffEventNotificationTest extends TestCase
{
    use RefreshDatabase;

    private InventoryStock $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        // Batas 0 mematikan peringatan stok; tes stok menyetel batasnya sendiri.
        Setting::where('key', 'inventory.low_stock_threshold')->update(['value' => '0']);

        $product = Product::create(['name' => 'Nastar', 'slug' => 'nastar', 'is_published' => true]);
        $variant = $product->variants()->create(['sku' => 'NAS-01', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);
        $this->stock = InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->value('id'), 'product_variant_id' => $variant->id, 'quantity' => 10]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function order(int $quantity = 2): Order
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => $quantity])->assertOk();

        $ref = $this->withHeader('Idempotency-Key', 'key-'.str()->random(20))->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi Uji',
            'customer_email' => 'budi@example.com',
            'customer_phone' => '081234567890',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt',
            'service' => 'ez',
        ])->assertCreated()->json('data.public_ref');

        return Order::where('public_ref', $ref)->firstOrFail();
    }

    /** @return list<string> judul notifikasi milik pengguna */
    private function titles(User $user): array
    {
        return $user->notifications()->get()->map(fn ($n) => $n->data['title'])->all();
    }

    public function test_paid_order_notifies_store_admin_and_warehouse_only(): void
    {
        $storeAdmin = $this->staff('store-admin');
        $warehouse = $this->staff('warehouse');
        $marketing = $this->staff('marketing');
        $order = $this->order();

        app(OrderStatusService::class)->markPaid($order);

        $title = "Pesanan {$order->order_number} sudah dibayar dan siap dikemas";
        $this->assertSame([$title], $this->titles($storeAdmin));
        $this->assertSame([$title], $this->titles($warehouse));
        $this->assertSame([], $this->titles($marketing));

        // Panggilan berulang (webhook ganda) tidak menggandakan notifikasi.
        app(OrderStatusService::class)->markPaid($order);
        $this->assertCount(1, $storeAdmin->notifications);
    }

    public function test_cancellation_chain_notifies_the_next_role_at_each_step(): void
    {
        $cs = $this->staff('customer-service');
        $storeAdmin = $this->staff('store-admin');
        $owner = $this->staff('owner');
        $order = $this->order();
        app(OrderStatusService::class)->markPaid($order);
        $storeAdmin->notifications()->delete();

        $service = app(CancellationService::class);
        $request = $service->submit($order->fresh(), ['reason' => 'Salah pilih rasa', 'channel' => 'email']);
        $this->assertSame(["Pengajuan pembatalan {$request->request_number} menunggu validasi"], $this->titles($cs));

        $service->validateRequest($request, $cs);
        $this->assertSame(["Pengajuan {$request->request_number} menunggu ACC Admin Toko"], $this->titles($storeAdmin));
        $this->assertCount(1, $cs->notifications, 'Pelaku sendiri tidak dinotifikasi ulang.');

        $service->approveByStore($request->fresh(), $storeAdmin);
        $this->assertSame(["Pengajuan {$request->request_number} menunggu ACC Pemilik"], $this->titles($owner));

        $service->approveByOwner($request->fresh(), $owner);
        $this->assertContains("Dana pesanan {$order->order_number} menunggu dikembalikan", $this->titles($storeAdmin));
        $this->assertCount(1, $owner->notifications, 'Pemilik yang meng-ACC tidak menerima notifikasi dananya sendiri.');
    }

    public function test_overdue_cancellation_reminds_cs_and_store_admin_once(): void
    {
        $cs = $this->staff('customer-service');
        $storeAdmin = $this->staff('store-admin');
        $service = app(CancellationService::class);
        $request = $service->submit($this->order(), ['reason' => 'Berubah pikiran', 'channel' => 'whatsapp']);
        $cs->notifications()->delete();

        $this->assertSame(0, $service->remindOverdue(), 'Belum lewat batas.');

        OrderCancellationRequest::whereKey($request->id)->update(['due_at' => now()->subHour()]);
        $this->assertSame(1, $service->remindOverdue());
        $this->assertSame(0, $service->remindOverdue(), 'Hanya sekali per pengajuan.');

        $title = "Pengajuan {$request->request_number} lewat batas proses";
        $this->assertSame([$title], $this->titles($cs));
        $this->assertContains($title, $this->titles($storeAdmin));
    }

    public function test_low_stock_warns_once_when_available_stock_crosses_the_threshold(): void
    {
        $storeAdmin = $this->staff('store-admin');
        $warehouse = $this->staff('warehouse');
        $inventory = app(InventoryService::class);
        Setting::where('key', 'inventory.low_stock_threshold')->update(['value' => '5']);

        // Batas 5 (di bawah 5 = menipis); stok 10 -> pesan 3 (tersedia 7): belum menipis.
        $this->order(3);
        $this->assertSame([], $this->titles($storeAdmin));

        // Pesan 3 lagi (tersedia 4): melewati batas.
        $this->order(3);
        $this->assertCount(1, $storeAdmin->notifications);
        $this->assertCount(1, $warehouse->notifications);
        $this->assertStringContainsString('NAS-01', $this->titles($storeAdmin)[0]);
        $this->assertStringContainsString('tersisa 4', $this->titles($storeAdmin)[0]);

        // Turun lagi masih di bawah batas: tidak diulang.
        $this->order(1);
        $this->assertCount(1, $storeAdmin->notifications);

        // Penyesuaian stok manual yang melewati batas juga memberi tahu, kecuali pelakunya.
        $inventory->adjust($this->stock->warehouse_id, $this->stock->product_variant_id, 'restock', 20, 'Terima', $warehouse);
        $inventory->adjust($this->stock->warehouse_id, $this->stock->product_variant_id, 'correction', -23, 'Rusak', $warehouse);
        $this->assertCount(2, $storeAdmin->fresh()->notifications);
        $this->assertCount(1, $warehouse->fresh()->notifications, 'Pelaku penyesuaian tidak dinotifikasi.');
    }

    public function test_default_threshold_matches_the_dashboard_label(): void
    {
        $storeAdmin = $this->staff('store-admin');
        Setting::where('key', 'inventory.low_stock_threshold')->update(['value' => '10']);

        $this->order(1); // tersedia 9, di bawah 10

        $this->assertCount(1, $storeAdmin->notifications);
    }

    public function test_threshold_comes_from_settings(): void
    {
        $storeAdmin = $this->staff('store-admin');
        Setting::where('key', 'inventory.low_stock_threshold')->update(['value' => '9']);

        $this->order(2); // tersedia 8, melewati batas 9

        $this->assertCount(1, $storeAdmin->notifications);
    }

    public function test_prune_deletes_old_read_and_very_old_unread_notifications(): void
    {
        $user = $this->staff('owner');
        $make = fn (int $daysAgo, bool $read) => DatabaseNotification::create([
            'id' => (string) str()->uuid(), 'type' => 'x', 'notifiable_type' => User::class, 'notifiable_id' => $user->id,
            'data' => ['title' => "n{$daysAgo}"], 'read_at' => $read ? now() : null, 'created_at' => now()->subDays($daysAgo), 'updated_at' => now(),
        ]);
        $make(31, true);   // dihapus
        $make(29, true);   // tetap
        $make(60, false);  // tetap
        $make(91, false);  // dihapus

        $this->artisan('notifications:prune')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['n29', 'n60'], $this->titles($user));
    }

    public function test_notification_list_is_paged_by_five_with_total(): void
    {
        $owner = $this->staff('owner');
        foreach (range(1, 12) as $n) {
            DatabaseNotification::create(['id' => (string) str()->uuid(), 'type' => 'x', 'notifiable_type' => User::class, 'notifiable_id' => $owner->id, 'data' => ['title' => "n{$n}"], 'created_at' => now()->subMinutes($n), 'updated_at' => now()]);
        }

        $this->actingAs($owner);
        $this->getJson('/api/v1/admin/notifications')->assertOk()
            ->assertJsonCount(5, 'data.items')
            ->assertJsonPath('data.meta.total', 12)
            ->assertJsonPath('data.meta.last_page', 3)
            ->assertJsonPath('data.unread_count', 12);
        $this->getJson('/api/v1/admin/notifications?page=3')->assertJsonCount(2, 'data.items');
    }
}
