<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderStatusService;
use App\Services\RevenueService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    /** Sepuluh bulan, Feb sampai Nov 2026, per bulan (tes yang memakai travelTo November 2026). */
    private const MONTHLY = '?from=2026-02-01&to=2026-11-30&group=month';

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
        $this->assertSame(['revenue', 'refunded', 'net', 'shipping_subsidy'], $series->keys()->all());
        // Periode bawaan: bulan ini sampai hari ini, per hari; pesanan dibayar hari ini ada di kolom terakhir.
        $this->assertSame(last($series['revenue']['values']), last($series['net']['values'])); // tanpa dana yang dikembalikan, bersih = kotor
        $this->assertGreaterThan(0, last($series['net']['values']));
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

        $data = $this->actingAs($this->staff('owner'))->getJson('/api/v1/admin/reports'.self::MONTHLY)->assertOk()->json('data.sales');

        $this->assertSame('Nov 2026', collect($data['chart']['labels'])->last());
        $this->assertSame('Nov', collect($data['chart']['axis_labels'])->last());
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

    private function claim(Order $order, string $reason, string $choice, string $status, ?string $createdAt, ?string $refundedAt = null, int $approved = 0, int $shipping = 0): Refund
    {
        $refund = Refund::create([
            'public_ref' => (string) str()->uuid(), 'refund_number' => 'RF-'.str()->upper(str()->random(8)), 'order_id' => $order->id, 'idempotency_key' => str()->random(30),
            'requester_email' => 'x@example.com', 'contact_preference' => 'email', 'reason_type' => $reason, 'customer_choice' => $choice, 'description' => 'uji',
            'requested_amount' => $approved, 'approved_amount' => $approved, 'shipping_amount' => $shipping, 'terms_version' => 'v1', 'terms_accepted_at' => now(),
            'status' => $status, 'due_at' => now()->addDay(), 'refunded_at' => $refundedAt,
        ]);
        $refund->forceFill(['created_at' => $createdAt])->save();

        return $refund;
    }

    private function cancelPaid(Order $order, string $cancelledAt, ?string $refundedAt): void
    {
        $order->forceFill(['status' => Order::CANCELLED, 'payment_status' => $refundedAt ? 'refunded' : 'refund_pending', 'cancelled_at' => $cancelledAt])->save();
        $request = $order->cancellationRequests()->create(['request_number' => 'CX-'.str()->upper(str()->random(8)), 'reason' => 'uji', 'channel' => 'whatsapp', 'status' => $refundedAt ? 'refunded' : 'approved', 'due_at' => now()->addDay()]);
        $request->forceFill(['refunded_at' => $refundedAt])->save();
    }

    public function test_monthly_return_recap_splits_returns_and_claims_and_matches_revenue_service(): void
    {
        // 16:59 UTC 31 Okt = 23:59 WIB 31 Okt (Oktober); 17:00 UTC = 00:00 WIB 1 Nov (November).
        $this->travelTo(CarbonImmutable::parse('2026-11-15 05:00:00', 'UTC'));
        $this->beforeApplicationDestroyed(fn () => $this->travelBack());
        $this->paidOrder();
        $order = Order::firstOrFail();

        $this->claim($order, 'return_unopened', 'refund', 'refunded', '2026-10-31 16:59:59', '2026-10-31 16:59:59', 30000, 10000);
        $this->claim($order, 'damaged', 'refund', 'refunded', '2026-11-01 17:00:00', '2026-10-31 17:00:00', 20000);
        $this->claim($order, 'damaged', 'resend', 'refunded', '2026-11-05 01:00:00', '2026-11-06 01:00:00', 99999); // kirim ulang: tidak mengeluarkan uang
        $this->claim($order, 'other', 'voucher', 'rejected', '2026-11-07 01:00:00');

        $rows = collect($this->actingAs($this->staff('owner'))->getJson('/api/v1/admin/reports'.self::MONTHLY)->assertOk()->json('data.returns'))->keyBy('label');

        $this->assertCount(10, $rows);
        $oct = $rows['Okt 2026'];
        $this->assertSame(1, $oct['submitted']); // 16:59 UTC = 23:59 WIB 31 Okt, masih Oktober
        $this->assertSame(1, $oct['returns']);
        $this->assertSame(40000, $oct['refunded_return']);
        $this->assertSame(40000, $oct['refunded_total']);

        $nov = $rows['Nov 2026'];
        $this->assertSame(['submitted' => 3, 'returns' => 0, 'claims' => 3, 'rejected' => 1, 'resend' => 1, 'voucher' => 1], collect($nov)->only(['submitted', 'returns', 'claims', 'rejected', 'resend', 'voucher'])->all());
        $this->assertSame(20000, $nov['refunded_claim']); // refunded_at 17:00 UTC 31 Okt = 00:00 WIB 1 Nov
        $this->assertSame(1, $nov['refunded_count']);

        // Konsisten dengan RevenueService: dana klaim per bulan sama dengan komponen "Dana dikembalikan" (tanpa pembatalan di data uji ini).
        $local = CarbonImmutable::parse('2026-11-15', 'Asia/Jakarta');
        $range = [$local->startOfMonth()->setTimezone('UTC'), $local->endOfMonth()->setTimezone('UTC')];
        $this->assertSame($nov['refunded_total'], app(RevenueService::class)->refunded($range));
    }

    public function test_cancelled_orders_stay_in_their_own_data_and_do_not_vanish_after_refund(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 05:00:00', 'UTC'));
        $this->beforeApplicationDestroyed(fn () => $this->travelBack());
        $this->paidOrder();
        $this->paidOrder();
        $this->paidOrder();
        [$refunded, $pending] = Order::orderBy('id')->get()->all();
        $total = (int) $refunded->total;

        $this->cancelPaid($refunded, '2026-11-10 03:00:00', '2026-11-12 03:00:00');
        $this->cancelPaid($pending, '2026-11-11 03:00:00', null);
        // Dibatalkan sebelum bayar: tercatat sebagai batal, tanpa uang dan tanpa paid_at.
        $unpaid = Order::orderByDesc('id')->first()->replicate(['public_ref', 'order_number', 'paid_at', 'idempotency_key', 'access_token_hash']);
        $unpaid->forceFill(['public_ref' => (string) str()->uuid(), 'idempotency_key' => str()->random(30), 'access_token_hash' => str()->random(64), 'order_number' => 'TF-UNPAID', 'status' => Order::CANCELLED, 'payment_status' => 'cancelled', 'total' => 77777, 'paid_at' => null, 'cancelled_at' => '2026-11-12 04:00:00'])->save();

        $data = $this->actingAs($this->staff('owner'))->getJson('/api/v1/admin/reports'.self::MONTHLY)->assertOk()->json('data');
        $nov = collect($data['cancellations']['rows'])->firstWhere('label', 'Nov 2026');

        $this->assertSame(3, $nov['cancelled']);
        $this->assertSame(1, $nov['unpaid']);
        $this->assertSame(2, $nov['paid']);
        $this->assertSame($total * 2, $nov['paid_value']); // 77777 milik pesanan belum bayar tidak ikut nilai terbayar
        $this->assertSame($total, $nov['refunded']);
        $this->assertSame(['count' => 1, 'value' => $total], $data['cancellations']['pending']);

        // D-131: pesanan batal (terbayar maupun tidak) tidak masuk pendapatan; dananya tidak dikurangkan lagi dari bersih.
        $series = collect($data['sales']['revenue_chart']['series'])->keyBy('key');
        $this->assertSame($total, $series['revenue']['values'][9]);
        $this->assertSame(0, $series['refunded']['values'][9]);
        $this->assertSame($total, $series['net']['values'][9]);
        $this->assertDatabaseHas('orders', ['id' => $unpaid->id]);
    }

    public function test_return_and_cancellation_recaps_follow_the_report_permissions(): void
    {
        $this->paidOrder();

        $data = $this->actingAs($this->staff('marketing'))->getJson('/api/v1/admin/reports')->assertOk()->json('data');
        $this->assertNull($data['returns']);
        $this->assertNull($data['cancellations']);

        foreach (['owner', 'super-admin'] as $role) {
            $data = $this->actingAs($this->staff($role))->getJson('/api/v1/admin/reports')->assertOk()->json('data');
            $days = count($data['sales']['chart']['labels']); // bawaan: per hari, tanggal 1 sampai hari ini
            $this->assertCount($days, $data['returns']);
            $this->assertCount($days, $data['cancellations']['rows']);
        }
    }

    public function test_return_recap_downloads_as_xlsx_with_the_same_numbers_as_the_screen(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 05:00:00', 'UTC'));
        $this->beforeApplicationDestroyed(fn () => $this->travelBack());
        $this->paidOrder();
        $order = Order::firstOrFail();
        $this->claim($order, 'return_unopened', 'refund', 'refunded', '2026-11-02 01:00:00', '2026-11-03 01:00:00', 30000, 10000);
        $this->claim($order, 'damaged', 'resend', 'approved', '2026-11-04 01:00:00');
        $owner = $this->staff('owner');

        $screen = $this->actingAs($owner)->getJson('/api/v1/admin/reports'.self::MONTHLY)->assertOk()->json('data.returns');
        $response = $this->actingAs($owner)->get('/api/v1/admin/reports/returns-export'.self::MONTHLY)->assertOk();
        $this->assertStringContainsString('rekap-retur-month-2026-02-01-sd-2026-11-30.xlsx', (string) $response->headers->get('Content-Disposition'));

        $rows = $this->xlsxRows($response);

        $this->assertSame(['Periode', 'Pengajuan', 'Retur', 'Klaim', 'Ditolak', 'Kirim ulang', 'Voucher', 'Refund', 'Dana retur', 'Dana klaim', 'Total dana'], $rows[0]);
        $this->assertCount(11, $rows); // judul + 10 bulan
        $this->assertSame(array_map(fn ($r) => array_values($r), $screen), array_slice($rows, 1));
        $this->assertSame(['Nov 2026', 2, 1, 1, 0, 1, 0, 1, 40000, 0, 40000], $rows[1]);

        // Audit tanpa isi data; tidak ada data pelanggan di berkas.
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.returns_export', 'actor_user_id' => $owner->id]);
        $this->assertStringNotContainsString('Budi Rahasia', json_encode($rows));
    }

    /** @return list<list<mixed>> */
    private function xlsxRows(TestResponse $response): array
    {
        $reader = new Reader;
        $reader->open($response->baseResponse->getFile()->getPathname());
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        return $rows;
    }

    public function test_cancellation_and_revenue_recaps_download_with_the_same_numbers_as_the_screen(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 05:00:00', 'UTC'));
        $this->beforeApplicationDestroyed(fn () => $this->travelBack());
        $this->paidOrder();
        $this->paidOrder();
        $cancelled = Order::orderBy('id')->firstOrFail();
        $this->cancelPaid($cancelled, '2026-11-10 01:00:00', null);
        $kept = (int) Order::whereNull('cancelled_at')->sum('total');
        $owner = $this->staff('owner');
        $data = $this->actingAs($owner)->getJson('/api/v1/admin/reports'.self::MONTHLY)->assertOk()->json('data');

        $rows = $this->xlsxRows($this->actingAs($owner)->get('/api/v1/admin/reports/cancellations-export'.self::MONTHLY)->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=pesanan-dibatalkan-month-2026-02-01-sd-2026-11-30.xlsx'));
        $this->assertSame(['Periode', 'Dibatalkan', 'Belum dibayar', 'Sudah dibayar', 'Nilai terbayar', 'Dana dikembalikan'], $rows[0]);
        $this->assertSame(array_map(fn ($r) => array_values($r), $data['cancellations']['rows']), array_slice($rows, 1));
        $this->assertSame(['Nov 2026', 1, 0, 1, (int) $cancelled->total, 0], $rows[1]);

        $rows = $this->xlsxRows($this->actingAs($owner)->get('/api/v1/admin/reports/revenue-export'.self::MONTHLY)->assertOk());
        $this->assertSame(['Periode', 'Pendapatan kotor', 'Dana dikembalikan', 'Pendapatan bersih', 'Subsidi ongkir'], $rows[0]);
        $this->assertCount(11, $rows);
        // Grafik di layar berurutan terlama dulu; berkas terbaru dulu. Nilainya harus sama.
        $series = collect($data['sales']['revenue_chart']['series'])->keyBy('key');
        foreach (['revenue' => 1, 'refunded' => 2, 'net' => 3, 'shipping_subsidy' => 4] as $key => $col) {
            $this->assertSame(array_reverse($series[$key]['values']), array_column(array_slice($rows, 1), $col), $key);
        }
        $this->assertSame('Nov 2026', $rows[1][0]);
        $this->assertSame($kept, $rows[1][1]); // pesanan batal tidak masuk pendapatan kotor (D-131)

        $this->assertDatabaseHas('audit_logs', ['action' => 'report.cancellations_export', 'actor_user_id' => $owner->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.revenue_export', 'actor_user_id' => $owner->id]);
    }

    public function test_report_exports_need_full_report_permission(): void
    {
        foreach (['returns', 'cancellations', 'revenue'] as $kind) {
            $this->actingAs($this->staff('marketing'))->get("/api/v1/admin/reports/{$kind}-export")->assertForbidden();
            $this->actingAs($this->staff('super-admin'))->get("/api/v1/admin/reports/{$kind}-export")->assertOk();
            auth()->forgetGuards();
            $this->getJson("/api/v1/admin/reports/{$kind}-export")->assertUnauthorized();
        }

        // Jenis lain tidak dikenal rute.
        $this->actingAs($this->staff('owner'))->get('/api/v1/admin/reports/vouchers-export')->assertNotFound();
    }

    public function test_default_period_is_this_month_until_today_per_day_in_jakarta_time(): void
    {
        // 01:00 WIB 18 Nov = 18:00 UTC 17 Nov: bawaan memakai tanggal Jakarta.
        $this->travelTo(CarbonImmutable::parse('2026-11-17 18:00:00', 'UTC'));
        $this->beforeApplicationDestroyed(fn () => $this->travelBack());
        $this->paidOrder();

        $data = $this->actingAs($this->staff('owner'))->getJson('/api/v1/admin/reports')->assertOk()->json('data');

        $this->assertSame(['from' => '2026-11-01', 'to' => '2026-11-18', 'group' => 'day', 'name' => 'hari'], $data['period']);
        $this->assertSame('Penjualan per hari', $data['sales']['chart']['title']);
        $this->assertCount(18, $data['sales']['chart']['labels']);
        $this->assertSame('18 Nov 2026', last($data['sales']['chart']['labels']));
        $this->assertSame('18 Nov', last($data['sales']['chart']['axis_labels']));
        $this->assertSame(1, last($data['sales']['chart']['series'][0]['values'])); // dibayar 01:00 WIB 18 Nov, bukan 17 Nov
        $this->assertSame('18 Nov 2026', $data['returns'][0]['label']); // tabel: terbaru di atas
    }

    public function test_weekly_and_yearly_groups_are_clipped_to_the_period(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 05:00:00', 'UTC'));
        $this->beforeApplicationDestroyed(fn () => $this->travelBack());
        $owner = $this->staff('owner');

        // 28 Okt (Rabu) sampai 18 Nov (Rabu): minggu pertama dan terakhir dipangkas, minggu mulai Senin.
        $labels = $this->actingAs($owner)->getJson('/api/v1/admin/reports?from=2026-10-28&to=2026-11-18&group=week')->assertOk()->json('data.sales.chart.labels');
        $this->assertSame(['28 Okt–1 Nov 2026', '2–8 Nov 2026', '9–15 Nov 2026', '16–18 Nov 2026'], $labels);

        $labels = $this->actingAs($owner)->getJson('/api/v1/admin/reports?from=2025-12-29&to=2026-01-04&group=week')->assertOk()->json('data.sales.chart.labels');
        $this->assertSame(['29 Des 2025–4 Jan 2026'], $labels);

        $data = $this->actingAs($owner)->getJson('/api/v1/admin/reports?from=2025-12-01&to=2026-11-30&group=year')->assertOk()->json('data');
        $this->assertSame(['2025', '2026'], $data['sales']['chart']['labels']);
        $this->assertSame('Pendapatan per tahun', $data['sales']['revenue_chart']['title']);
    }

    public function test_period_is_validated(): void
    {
        $owner = $this->staff('owner');

        $this->actingAs($owner)->getJson('/api/v1/admin/reports?from=2026-08-01&to=2026-11-01&group=day')->assertUnprocessable()->assertJsonValidationErrors('to'); // 93 hari
        $this->actingAs($owner)->getJson('/api/v1/admin/reports?from=2026-08-02&to=2026-11-01&group=day')->assertOk(); // 92 hari
        $this->actingAs($owner)->getJson('/api/v1/admin/reports?from=2025-11-01&to=2026-11-02&group=month')->assertUnprocessable()->assertJsonValidationErrors('to'); // 367 hari
        $this->actingAs($owner)->getJson('/api/v1/admin/reports?from=2026-11-10&to=2026-11-01&group=week')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->actingAs($owner)->getJson('/api/v1/admin/reports?from=2026-11-01&to=2026-11-10&group=quarter')->assertUnprocessable()->assertJsonValidationErrors('group');
        $this->actingAs($owner)->getJson('/api/v1/admin/reports?from=2026-11-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->actingAs($owner)->getJson('/api/v1/admin/reports/revenue-export?from=2026-08-01&to=2026-11-01&group=day')->assertUnprocessable();
    }

    public function test_export_follows_the_displayed_period_and_group(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 05:00:00', 'UTC'));
        $this->beforeApplicationDestroyed(fn () => $this->travelBack());
        $this->paidOrder();
        $owner = $this->staff('owner');
        $query = '?from=2026-11-09&to=2026-11-15&group=day';

        $screen = $this->actingAs($owner)->getJson('/api/v1/admin/reports'.$query)->assertOk()->json('data.sales.revenue_chart');
        $response = $this->actingAs($owner)->get('/api/v1/admin/reports/revenue-export'.$query)->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=pendapatan-day-2026-11-09-sd-2026-11-15.xlsx');
        $rows = $this->xlsxRows($response);

        $this->assertCount(8, $rows); // judul + 7 hari
        $this->assertSame(array_reverse($screen['labels']), array_column(array_slice($rows, 1), 0));
        $this->assertSame('15 Nov 2026', $rows[1][0]);
        $this->assertGreaterThan(0, $rows[1][1]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.revenue_export', 'actor_user_id' => $owner->id]);
    }
}
