<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\MarketingDraft;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DashboardService;
use App\Services\OrderStatusService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
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
        $this->assertCount($role === 'super-admin' ? 3 : 4, $data['kpis']);
        $this->assertSame('attention', $this->kpi($data, 'attention_total')['action'] ?? null);
        $this->assertNotEmpty($data['chart']['labels']);
        $this->assertNotEmpty($data['chart']['series']);
        $this->assertNotNull($data['gauge']);
        $this->assertCount(match ($role) {
            'super-admin' => 4,
            'customer-service' => 6,
            'owner' => 7,
            default => 3,
        }, $data['lists']);
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

    public function test_owner_sees_content_drafts_waiting_for_a_decision_and_others_do_not(): void
    {
        $marketing = $this->staff('marketing');
        $draft = new MarketingDraft(['type' => 'caption', 'title' => 'Caption Lebaran', 'body' => 'Isi']);
        $draft->forceFill(['status' => MarketingDraft::IN_REVIEW, 'created_by_user_id' => $marketing->id, 'submitted_at' => now()])->save();
        (new MarketingDraft(['type' => 'caption', 'title' => 'Masih draf', 'body' => 'Isi']))->forceFill(['status' => MarketingDraft::DRAFT, 'created_by_user_id' => $marketing->id])->save();

        $owner = $this->dashboard('owner');
        $queue = collect($owner['lists'])->firstWhere('key', 'draft_review_queue');
        $this->assertCount(1, $queue['rows']);
        $this->assertSame('Caption Lebaran', $queue['rows'][0]['title']);
        $this->assertSame(1, collect($owner['attention'])->firstWhere('key', 'draft_review')['count']);

        $refunds = collect($owner['lists'])->firstWhere('key', 'refund_decide_queue');
        $this->assertSame([], $refunds['rows']);

        foreach (['marketing', 'customer-service', 'warehouse', 'store-admin'] as $role) {
            $data = $this->dashboard($role);
            $this->assertNull(collect($data['lists'])->firstWhere('key', 'draft_review_queue'), $role);
            $this->assertNull(collect($data['attention'])->firstWhere('key', 'draft_review'), $role);
        }
    }

    public function test_owner_business_stats_use_fixed_numbers_and_stay_owner_only(): void
    {
        // Tanpa pesanan dibayar: pembagian nol tidak boleh galat.
        $empty = collect($this->dashboard('owner')['business_stats'])->keyBy('key');
        $this->assertSame('-', $empty['avg_order']['value']);
        $this->assertSame('-', $empty['cancel_rate']['value']);

        $first = $this->paidOrder(1);
        $second = $this->paidOrder(3);
        $cancelled = $this->paidOrder(1);
        // Keadaan nyata setelah pesanan terbayar dibatalkan (CancellationService): bukan lagi payment_status 'paid'.
        $cancelled->forceFill(['cancelled_at' => now(), 'status' => Order::CANCELLED, 'payment_status' => 'refund_pending'])->save();
        User::factory()->count(2)->create();
        User::factory()->create(['created_at' => now()->subMonths(2)]);

        $stats = collect($this->dashboard('owner')['business_stats'])->keyBy('key');
        // Rata-rata = pendapatan kotor dibagi pesanan dibayar bulan ini (paid_at), termasuk yang kemudian dibatalkan.
        $everPaid = Order::whereNotNull('paid_at')->get();
        $this->assertCount(3, $everPaid);
        $average = (int) round($everPaid->sum('total') / 3);
        $this->assertSame('Rp'.number_format($average, 0, ',', '.'), $stats['avg_order']['value']);
        // Dua akun baru bulan ini; akun staf yang dibuat tes tidak dihitung sebagai pelanggan.
        $this->assertSame('2', $stats['new_customers']['value']);
        // Kelompok = 3 pesanan dibayar bulan ini, 1 di antaranya dibatalkan: 33,3% dan tidak pernah lebih dari 100%.
        $this->assertSame('33,3%', $stats['cancel_rate']['value']);
        $this->assertSame('1 dari 3 pesanan dibayar bulan ini dibatalkan', $stats['cancel_rate']['foot']);
        $this->assertSame('Bulan lalu belum ada pembanding', $stats['avg_order']['foot']);

        foreach (['store-admin', 'customer-service', 'warehouse', 'marketing'] as $role) {
            $this->assertSame([], $this->dashboard($role)['business_stats'], $role);
        }

        // Super Admin memegang izin yang sama dan ikut melihatnya (keputusan pengguna).
        $this->assertSame(['avg_order', 'new_customers', 'cancel_rate'], collect($this->dashboard('super-admin')['business_stats'])->pluck('key')->all());
    }

    public function test_net_revenue_subtracts_money_that_actually_left_in_the_month_it_left(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 05:00:00', 'UTC'));
        $this->beforeApplicationDestroyed(fn () => $this->travelBack());

        $first = $this->paidOrder(1);
        $second = $this->paidOrder(2);
        $third = $this->paidOrder(1);
        $gross = (int) $first->total + (int) $second->total + (int) $third->total;

        $kpi = fn () => $this->kpi($this->dashboard('owner'), 'revenue_month');
        $this->assertSame($gross, $kpi()['value']);

        // Refund barang bermasalah yang baru disetujui belum mengurangi: uangnya belum keluar.
        $refund = $this->refund($second, Refund::APPROVED, now()->addDays(3));
        $refund->forceFill(['approved_amount' => 20000, 'shipping_amount' => 5000, 'customer_choice' => 'refund'])->save();
        $this->assertSame($gross, $kpi()['value']);

        // Setelah dana ditandai dikembalikan: dikurangi nilai disetujui ditambah ongkir.
        $refund->forceFill(['status' => Refund::REFUNDED, 'refunded_at' => now()])->save();
        $this->assertSame($gross - 25000, $kpi()['value']);
        $this->assertStringContainsString('dikembalikan Rp25.000', $kpi()['foot']);

        // Kirim ulang tidak mengeluarkan uang.
        $resend = $this->refund($third, Refund::REFUNDED, now()->addDays(3));
        $resend->forceFill(['approved_amount' => 10000, 'customer_choice' => 'resend', 'refunded_at' => now()])->save();
        $this->assertSame($gross - 25000, $kpi()['value']);

        // Pembatalan pesanan terbayar: pesanan tetap ada di kotor bulan asalnya, dana dikurangi di bulan dikembalikannya.
        $order = $first->fresh();
        $order->forceFill(['status' => Order::CANCELLED, 'payment_status' => 'refunded', 'cancelled_at' => now()])->save();
        (new OrderCancellationRequest)->forceFill([
            'order_id' => $order->id, 'request_number' => 'CR-'.str()->upper(str()->random(8)), 'status' => OrderCancellationRequest::REFUNDED,
            'reason' => 'Salah pesan', 'channel' => 'email', 'due_at' => now()->addDays(7), 'refunded_at' => now(),
        ])->save();
        $this->assertSame($gross - 25000 - (int) $first->total, $kpi()['value']);

        // Bulan depan: pendapatan bulan ini tidak berubah mundur, dana yang dikembalikan bulan itu menjadi miliknya.
        $this->travelTo(CarbonImmutable::parse('2026-12-02 05:00:00', 'UTC'));
        $refund2 = $this->refund($second, Refund::REFUNDED, now()->addDays(3));
        $refund2->forceFill(['approved_amount' => 7000, 'shipping_amount' => 0, 'customer_choice' => 'refund', 'refunded_at' => now()])->save();
        $data = $this->dashboard('owner');
        $chart = collect($data['chart']['series'])->keyBy('key');
        $this->assertSame($gross, $chart['revenue']['values'][8]);
        $this->assertSame(25000 + (int) $first->total, $chart['refunded']['values'][8]);
        $this->assertSame(7000, $chart['refunded']['values'][9]);
        $this->assertSame(-7000, $this->kpi($data, 'revenue_month')['value']);
    }

    public function test_month_and_day_boundaries_follow_jakarta_time_not_utc(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 20:30:00', 'UTC'));
        $this->beforeApplicationDestroyed(fn () => $this->travelBack());

        // 03:30 WIB tanggal 1 November: sudah November di toko, masih 31 Oktober menurut UTC.
        $november = $this->paidOrder(1);
        $october = $this->paidOrder(1);
        $october->forceFill(['paid_at' => CarbonImmutable::parse('2026-10-31 16:30:00', 'UTC')])->save(); // 23:30 WIB 31 Oktober

        $data = $this->dashboard('owner');
        $this->assertSame(1, $this->kpi($data, 'orders_month')['value']);
        $this->assertSame((int) $november->total, $this->kpi($data, 'revenue_month')['value']);
        $this->assertSame('Nov', collect($data['chart']['labels'])->last());
        $this->assertSame((int) $october->total, $data['chart']['series'][0]['values'][8]);

        // Pesanan "hari ini" ikut tanggal Jakarta: keduanya dibuat sejak 23:30 WIB kemarin, hanya yang 03:30 WIB masuk hari ini.
        $this->assertSame(2, $this->kpi($this->dashboard('super-admin'), 'orders_today')['value']);
    }

    public function test_each_business_tile_needs_its_own_permission(): void
    {
        $this->paidOrder(1);
        $role = Role::findByName('owner');
        $keys = function () {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return collect($this->actingAs($this->staff('owner'))->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.business_stats'))->pluck('key')->all();
        };

        $this->assertSame(['avg_order', 'new_customers', 'cancel_rate'], $keys());

        // Izin datang dari role, jadi dicabut dari role: hanya tile yang izinnya dicabut yang hilang.
        $expected = ['payments.view' => ['new_customers', 'cancel_rate'], 'customers.view' => ['avg_order', 'cancel_rate'], 'orders.view' => ['avg_order', 'new_customers']];
        foreach ($expected as $permission => $remaining) {
            $role->revokePermissionTo($permission);
            $this->assertSame($remaining, $keys(), $permission);
            $role->givePermissionTo($permission);
        }
    }

    public function test_stock_alerts_reach_owner_and_warehouse_but_not_roles_without_stock_access(): void
    {
        $this->paidOrder(2);

        foreach (['owner', 'warehouse', 'store-admin'] as $role) {
            $list = collect($this->dashboard($role)['lists'])->firstWhere('key', 'stock_alerts');
            $this->assertSame('AdminStock', $list['more']['to'], $role);
            $this->assertSame('Tipis: Nastar', $list['rows'][0]['title'], $role);
        }

        foreach (['marketing'] as $role) {
            $this->assertNull(collect($this->dashboard($role)['lists'])->firstWhere('key', 'stock_alerts'), $role);
        }
    }

    public function test_owner_gets_report_and_audit_links_and_other_roles_only_what_they_may_open(): void
    {
        $owner = $this->dashboard('owner');
        $this->assertSame('AdminAudit', collect($owner['lists'])->firstWhere('key', 'audit_recent')['more']['to']);
        $this->assertSame('AdminReports', collect($owner['lists'])->firstWhere('key', 'top_products')['more']['to']);

        // Marketing boleh membuka laporan ringkas, tetapi tidak punya audit log.
        $marketing = $this->dashboard('marketing');
        $this->assertSame('AdminReports', collect($marketing['lists'])->firstWhere('key', 'top_products')['more']['to']);
        $this->assertNull(collect($marketing['lists'])->firstWhere('key', 'audit_recent'));

        foreach (['store-admin', 'customer-service', 'warehouse'] as $role) {
            $lists = collect($this->dashboard($role)['lists']);
            $this->assertNull($lists->firstWhere('key', 'audit_recent'), $role);
            $this->assertNull($lists->firstWhere('key', 'top_products')['more'] ?? null, $role);
        }
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

    public function test_low_stock_limit_follows_the_setting(): void
    {
        $this->paidOrder(2); // tersedia 8
        Setting::where('key', 'inventory.low_stock_threshold')->update(['value' => '5']);

        $this->assertSame(0, $this->kpi($this->dashboard('warehouse'), 'low_stock')['value']);

        Setting::where('key', 'inventory.low_stock_threshold')->update(['value' => '9']);

        $this->assertSame(1, $this->kpi($this->dashboard('warehouse'), 'low_stock')['value']);
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

    public function test_super_admin_attention_total_does_not_double_count_overlapping_alerts(): void
    {
        $order = $this->paidOrder();
        $this->withSession(["order_access.{$order->public_ref}" => true])
            ->postJson("/api/v1/orders/{$order->public_ref}/cancellation", ['reason' => 'Salah pesan', 'channel' => 'email'])->assertCreated();
        OrderCancellationRequest::query()->update(['due_at' => now()->subDay()]);
        $order->forceFill(['status' => Order::PROCESSING])->save();

        $data = $this->dashboard('super-admin');
        $counts = collect($data['attention'])->pluck('count', 'key');

        $this->assertSame(1, $counts['cancel_overdue']);
        $this->assertSame(1, $counts['awaiting_waybill']);
        $this->assertSame($counts->sum() - 2, $this->kpi($data, 'attention_total')['value']);
    }

    private function refund(Order $order, string $status, \DateTimeInterface $due): Refund
    {
        return Refund::create([
            'public_ref' => (string) str()->uuid(), 'refund_number' => 'RF-'.str()->upper(str()->random(8)), 'order_id' => $order->id, 'idempotency_key' => str()->random(30),
            'requester_email' => 'budi@example.com', 'contact_preference' => 'email', 'reason_type' => 'damaged', 'description' => 'Rusak', 'requested_amount' => 10000,
            'terms_version' => 'v1', 'terms_accepted_at' => now(), 'status' => $status, 'due_at' => $due,
        ]);
    }

    public function test_overdue_refund_is_counted_once_per_role_by_the_stages_it_can_see(): void
    {
        $order = $this->paidOrder();
        $this->refund($order, Refund::UNDER_REVIEW, now()->subDay());

        // Pemilik melihat tahap "menunggu keputusan": refund lewat batas itu hanya dihitung sekali (1, bukan 2).
        $owner = $this->dashboard('owner');
        $counts = collect($owner['attention'])->keyBy('key');
        $this->assertSame(1, $counts['refund_decide']['count']);
        $this->assertSame(1, $counts['refund_overdue']['count']);
        $this->assertTrue($counts['refund_overdue']['overlap']);
        $this->assertSame(1, $counts['refund_overdue']['covered']);
        $this->assertSame($counts->sum('count') - 1, $this->kpi($owner, 'attention_total')['value']);

        // CS memproses tetapi tidak memutuskan: refund under_review lewat batas tidak tampil di tahap CS, jadi tetap dihitung (tidak ditutupi).
        $cs = $this->dashboard('customer-service');
        $counts = collect($cs['attention'])->keyBy('key');
        $this->assertArrayNotHasKey('refund_decide', $counts->all());
        $this->assertSame(1, $counts['refund_overdue']['count']);
        $this->assertSame(0, $counts['refund_overdue']['covered']);
        $this->assertFalse($counts['refund_overdue']['overlap']);
        $this->assertSame($counts->sum('count'), $this->kpi($cs, 'attention_total')['value']);

        // Refund disetujui dan lewat batas tampil di tahap "diselesaikan" milik CS, jadi tidak dijumlah dua kali.
        Refund::query()->update(['status' => Refund::APPROVED]);
        $cs = $this->dashboard('customer-service');
        $counts = collect($cs['attention'])->keyBy('key');
        $this->assertSame(1, $counts['refund_payout']['count']);
        $this->assertSame(1, $counts['refund_overdue']['covered']);
        $this->assertSame($counts->sum('count') - 1, $this->kpi($cs, 'attention_total')['value']);
    }

    public function test_overdue_cancellation_total_counts_rows_hidden_from_the_roles_stages(): void
    {
        $a = $this->paidOrder();
        $this->withSession(["order_access.{$a->public_ref}" => true])->postJson("/api/v1/orders/{$a->public_ref}/cancellation", ['reason' => 'Salah pesan', 'channel' => 'email'])->assertCreated();
        $b = $this->paidOrder();
        $this->withSession(["order_access.{$b->public_ref}" => true])->postJson("/api/v1/orders/{$b->public_ref}/cancellation", ['reason' => 'Salah pesan', 'channel' => 'email'])->assertCreated();

        // Satu STORE_APPROVED segar, satu SUBMITTED lewat batas. Pemilik hanya melihat tahap STORE_APPROVED.
        [$first, $second] = OrderCancellationRequest::orderBy('id')->get()->all();
        $first->forceFill(['status' => OrderCancellationRequest::STORE_APPROVED, 'due_at' => now()->addDay()])->save();
        $second->forceFill(['status' => OrderCancellationRequest::SUBMITTED, 'due_at' => now()->subDay()])->save();

        $owner = $this->dashboard('owner');
        $counts = collect($owner['attention'])->keyBy('key');
        $this->assertSame(1, $counts['cancel_final']['count']);
        $this->assertSame(1, $counts['cancel_overdue']['count']);
        $this->assertFalse($counts['cancel_overdue']['overlap']);
        // Dua pembatalan berbeda, bukan satu: pengajuan lewat batas itu tidak tampil di tahap Pemilik, jadi tidak ditutupi.
        $this->assertSame(0, $counts['cancel_overdue']['covered']);
        $this->assertSame($counts->sum('count'), $this->kpi($owner, 'attention_total')['value']);

        // Super Admin melihat semua tahap: pengajuan lewat batas itu sudah ada di tahap validasi, jadi ditutupi.
        $superCounts = collect($this->dashboard('super-admin')['attention'])->keyBy('key');
        $this->assertSame(1, $superCounts['cancel_overdue']['covered']);
        $this->assertTrue($superCounts['cancel_overdue']['overlap']);
    }

    public function test_super_admin_total_does_not_count_stuck_orders_twice(): void
    {
        $order = $this->paidOrder();
        // Dibayar lebih dari 72 jam lalu dan belum diproses: ada di antrean kemas dan di pemeriksaan pesanan macet.
        $order->forceFill(['paid_at' => now()->subHours(100)])->save();

        $data = $this->dashboard('super-admin');
        $counts = collect($data['attention'])->keyBy('key');

        $this->assertSame(1, $counts['to_pack']['count']);
        $this->assertSame(1, $counts['stuck_orders']['count']);
        $this->assertSame(1, $counts['stuck_orders']['covered']);
        $this->assertTrue($counts['stuck_orders']['overlap']);
        $this->assertSame($counts->sum('count') - 1, $this->kpi($data, 'attention_total')['value']);
    }

    #[DataProvider('roles')]
    public function test_every_role_has_a_clickable_attention_card_that_matches_its_own_list(string $role): void
    {
        $order = $this->paidOrder();
        $order->forceFill(['status' => Order::PROCESSING])->save();

        $data = $this->dashboard($role);
        $card = $this->kpi($data, 'attention_total');

        $this->assertSame('Perlu tindakan', $card['label']);
        $this->assertSame('attention', $card['action']);
        $this->assertSame(
            collect($data['attention'])->sum(fn ($item) => $item['count'] - $item['covered']),
            $card['value'],
        );

        foreach ($data['attention'] as $item) {
            $this->assertSame(['key', 'text', 'tone', 'count', 'to', 'covered', 'overlap'], array_keys($item));
            $this->assertIsBool($item['overlap']);
            $this->assertGreaterThanOrEqual(0, $item['covered']);
            $this->assertLessThanOrEqual($item['count'], $item['covered']);
        }
    }

    public function test_attention_card_only_counts_what_the_role_may_see(): void
    {
        $order = $this->paidOrder();
        $order->forceFill(['status' => Order::PROCESSING])->save();

        $marketing = collect($this->dashboard('marketing')['attention'])->pluck('key');
        $this->assertNotContains('to_pack', $marketing);
        $this->assertNotContains('awaiting_waybill', $marketing);

        // Gudang melihat antrean kemas dan resi menunggu: resi menunggu sudah ada di antrean kemas, jadi tidak dijumlah dua kali.
        $warehouse = $this->dashboard('warehouse');
        $items = collect($warehouse['attention'])->keyBy('key');
        $this->assertTrue($items['awaiting_waybill']['overlap']);
        $this->assertFalse($items['to_pack']['overlap']);
        $this->assertSame($items['to_pack']['count'] + $items['stock_low']['count'], $this->kpi($warehouse, 'attention_total')['value']);

        // Admin Toko tidak melihat antrean kemas, jadi resi menunggu dihitung penuh.
        $store = collect($this->dashboard('store-admin')['attention'])->keyBy('key');
        $this->assertFalse($store['awaiting_waybill']['overlap']);
    }

    public function test_attention_card_is_calm_when_nothing_needs_action(): void
    {
        $card = $this->kpi($this->dashboard('marketing'), 'attention_total');

        $this->assertSame(0, $card['value']);
        $this->assertSame('Tidak ada yang perlu ditindaklanjuti', $card['foot']);
    }

    public function test_super_admin_dashboard_shows_staff_attention_and_audit_widgets(): void
    {
        $this->staff('owner', ['mfa_totp_confirmed_at' => null]);

        $data = $this->dashboard('super-admin');

        $this->assertSame(2, $this->kpi($data, 'staff_active')['value']);
        $this->assertContains('staff_no_mfa', array_column($data['attention'], 'key'));
        $this->assertSame(['audit_recent', 'attention', 'integrations', 'top_products'], array_column($data['lists'], 'key'));
        $this->assertSame('Pesanan per bulan', $data['chart']['title']);
    }

    /** @return array<string, array<string, mixed>> baris integrasi menurut judul */
    private function integrationRows(): array
    {
        $list = collect($this->dashboard('super-admin')['lists'])->firstWhere('key', 'integrations');

        return collect($list['rows'])->keyBy('title')->all();
    }

    private function configureIntegrations(array $override = []): void
    {
        config([
            'services.midtrans.server_key' => 'SB-Mid-server-rahasia123',
            'services.midtrans.client_key' => 'SB-Mid-client-publik123',
            'services.midtrans.is_production' => false,
            'services.biteship.key' => 'biteship_test.kunci-rahasia',
            'services.biteship.origin_postal_code' => '65111',
            'services.biteship.webhook_secret' => 'rahasia-webhook-yang-panjang-sekali',
            // Layanan pendukung siap di environment non-lokal.
            'mail.default' => 'resend',
            'services.resend.key' => 're_kunci-rahasia-email',
            'mail.from.address' => 'toko@tuffero.com',
            'services.turnstile.secret' => 'ts-rahasia-turnstile',
            'services.turnstile.site_key' => 'site-key-publik',
            ...$override,
        ]);
    }

    public function test_integration_marker_flags_missing_keys_and_webhook_secret(): void
    {
        config(['services.midtrans.server_key' => null, 'services.midtrans.client_key' => null, 'services.biteship.key' => null, 'services.biteship.webhook_secret' => null]);

        $rows = $this->integrationRows();

        $this->assertSame('Bermasalah', $rows['Midtrans']['chip']['text']);
        $this->assertSame('Bermasalah', $rows['Biteship']['chip']['text']);
        $this->assertStringContainsString('Rahasia webhook kosong', $rows['Biteship']['subtitle']);
        $this->assertContains('integration_issue', array_column($this->dashboard('super-admin')['attention'], 'key'));
    }

    public function test_integration_marker_is_ready_when_configured_and_never_leaks_secrets(): void
    {
        $this->configureIntegrations();

        $json = json_encode($this->dashboard('super-admin'));
        $rows = $this->integrationRows();

        $this->assertSame('Siap', $rows['Midtrans']['chip']['text']);
        $this->assertSame('Siap', $rows['Biteship']['chip']['text']);
        $this->assertNotContains('integration_issue', array_column($this->dashboard('super-admin')['attention'], 'key'));

        foreach (['SB-Mid-server-rahasia123', 'SB-Mid-client-publik123', 'biteship_test.kunci-rahasia', 'rahasia-webhook-yang-panjang-sekali'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_integration_marker_catches_mode_and_key_mismatch_and_weak_secret(): void
    {
        $this->configureIntegrations(['services.midtrans.is_production' => true, 'services.biteship.webhook_secret' => 'pendek']);

        $rows = $this->integrationRows();

        $this->assertSame('Bermasalah', $rows['Midtrans']['chip']['text']);
        $this->assertStringContainsString('kunci sandbox', $rows['Midtrans']['subtitle']);
        $this->assertSame('Periksa', $rows['Biteship']['chip']['text']);
        $this->assertStringContainsString('terlalu pendek', $rows['Biteship']['subtitle']);
    }

    public function test_integration_marker_is_only_for_super_admin(): void
    {
        foreach (['owner', 'store-admin', 'customer-service', 'warehouse', 'marketing'] as $role) {
            $data = $this->dashboard($role);

            $this->assertNotContains('integrations', array_column($data['lists'], 'key'), $role);
            $this->assertNotContains('integration_issue', array_column($data['attention'], 'key'), $role);
        }
    }

    public function test_account_with_several_roles_always_gets_the_highest_layout(): void
    {
        $user = $this->staff('marketing');
        $user->assignRole('super-admin');

        $this->assertSame('super-admin', $this->actingAs($user)->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.role'));
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

        // Cache pemeriksaan dimatikan di uji (TestCase); angka ini sudah termasuk pemeriksaan sistem, keamanan, pesanan macet, integrasi, dan refund.
        $this->assertLessThan(90, $queries);
    }
}
