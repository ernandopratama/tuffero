<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderStatusService;
use App\Services\SalesExportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class SalesExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $product = Product::create(['name' => 'Nastar', 'slug' => 'nastar', 'is_published' => true]);
        $variant = $product->variants()->create(['sku' => 'NAS-01', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);
        InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->value('id'), 'product_variant_id' => $variant->id, 'quantity' => 50]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    /** Pesanan terbayar dengan event bayar Midtrans. */
    private function paidOrder(string $name = 'Budi Uji', string $type = 'bank_transfer', string $paidAt = '2026-11-10 07:05:09'): Order
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2])->assertOk();
        $ref = $this->withHeader('Idempotency-Key', 'key-'.str()->random(20))->postJson('/api/v1/checkout', [
            'customer_name' => $name, 'customer_email' => 'budi@example.com', 'customer_phone' => '081234567890',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt', 'service' => 'ez',
        ])->assertCreated()->json('data.public_ref');

        $order = Order::where('public_ref', $ref)->firstOrFail();
        app(OrderStatusService::class)->markPaid($order);
        $order->forceFill(['paid_at' => $paidAt, 'created_at' => '2026-11-09 17:30:00'])->save();
        PaymentEvent::create([
            'order_id' => $order->id, 'transaction_id' => 'trx-'.$order->id, 'transaction_status' => 'settlement', 'payment_type' => $type,
            'gross_amount' => $order->total, 'payload' => ['settlement_time' => '2026-11-10 14:05:09'],
        ]);

        return $order->fresh();
    }

    /**
     * @param  array<string, string>  $query
     * @return list<list<mixed>> semua baris, termasuk judul
     */
    private function export(User $user, array $query = ['from' => '2026-11-01', 'to' => '2026-11-30']): array
    {
        $response = $this->actingAs($user)->get('/api/v1/admin/reports/sales-export?'.http_build_query($query))->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();

        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        @unlink($path);

        return $rows;
    }

    /** Nilai sel menurut huruf kolom di baris data. @param  list<mixed>  $row */
    private function cell(array $row, string $column): mixed
    {
        $n = 0;
        foreach (str_split($column) as $char) {
            $n = $n * 26 + (ord($char) - 64);
        }

        $value = $row[$n - 1] ?? null;

        return $value === '' ? null : $value;
    }

    public function test_header_has_the_49_template_columns_in_order(): void
    {
        $this->paidOrder();
        $rows = $this->export($this->staff('owner'));

        $this->assertCount(49, SalesExportService::HEADERS);
        $this->assertSame(SalesExportService::HEADERS, array_slice($rows[0], 0, 49));
        $this->assertSame('*Nama Kontak', $rows[0][0]);
        $this->assertSame('*Nomor Tagihan', $rows[0][5]);
        $this->assertSame('*Harga Produk', $rows[0][22]);
        $this->assertSame('*Status Dibayar (Ya / Tidak / Bertahap)', $rows[0][36]);
        $this->assertSame('Kode Akun Pembayaran', $rows[0][40]);
        $this->assertSame('ID Kontak', $rows[0][48]);
    }

    public function test_header_row_is_blue_frozen_and_columns_fit_the_titles(): void
    {
        $this->paidOrder();
        $response = $this->actingAs($this->staff('owner'))->get('/api/v1/admin/reports/sales-export?from=2026-11-01&to=2026-11-30')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $styles = $zip->getFromName('xl/styles.xml');
        $zip->close();
        @unlink($path);

        $this->assertStringContainsString('state="frozen"', $sheet); // judul tetap di atas
        $this->assertStringContainsString('ySplit="1"', $sheet);
        $this->assertStringContainsString('2E75B6', $styles); // latar biru
        $this->assertStringContainsString('<b/>', $styles);

        preg_match_all('/<col [^>]*min="(\d+)"[^>]*width="([\d.]+)"/', $sheet, $m, PREG_SET_ORDER);
        $widths = [];
        foreach ($m as $col) {
            $widths[(int) $col[1]] = (float) $col[2];
        }
        $this->assertCount(49, $widths);
        foreach (SalesExportService::HEADERS as $i => $title) {
            $this->assertGreaterThanOrEqual(mb_strlen($title), $widths[$i + 1], $title); // semua judul muat
        }
    }

    public function test_each_column_follows_the_approved_rules(): void
    {
        $order = $this->paidOrder();
        $rows = $this->export($this->staff('owner'));

        $this->assertCount(2, $rows);
        $row = $rows[1];

        $this->assertSame('Budi Uji', $this->cell($row, 'A'));
        $this->assertSame('budi@example.com', $this->cell($row, 'B'));
        $this->assertSame('Jl. Uji 1, Klojen, Malang, Jawa Timur, 65111', $this->cell($row, 'C'));
        $this->assertSame('081234567890', $this->cell($row, 'D')); // +62 menjadi 0
        $this->assertNull($this->cell($row, 'E'));
        $this->assertSame('trx-'.$order->id, $this->cell($row, 'F')); // transaction_id Midtrans
        $this->assertSame($order->order_number, $this->cell($row, 'G'));
        $this->assertSame('10/11/2026', $this->cell($row, 'H')); // dibuat 17:30 UTC 9 Nov = 00:30 WIB 10 Nov
        $this->assertSame('10/11/2026', $this->cell($row, 'I')); // dibayar 07:05 UTC = 14:05 WIB
        $this->assertSame('MLG', $this->cell($row, 'J'));
        $this->assertNull($this->cell($row, 'K'));
        $this->assertSame('JNT EZ', $this->cell($row, 'M'));
        $this->assertSame('Tidak', $this->cell($row, 'O'));
        $this->assertSame('Nastar - Standar', $this->cell($row, 'P'));
        $this->assertSame('NAS-01', $this->cell($row, 'Q'));
        $this->assertSame(2, $this->cell($row, 'S'));
        $this->assertSame(50000, $this->cell($row, 'U'));
        $this->assertNull($this->cell($row, 'V')); // tanpa harga normal
        $this->assertSame(50000, $this->cell($row, 'W'));
        $this->assertSame('Biaya Transaksi Midtrans - Virtual Account (termasuk PPN 11%)', $this->cell($row, 'AB'));
        $this->assertSame(4440, $this->cell($row, 'AC'));
        $this->assertSame('Ya', $this->cell($row, 'AK'));
        $this->assertSame((int) $order->total, $this->cell($row, 'AM'));
        $this->assertSame('10/11/2026 14:05', $this->cell($row, 'AN'));
        $this->assertNull($this->cell($row, 'AO'));
        $this->assertSame('website', $this->cell($row, 'AQ'));

        // Kolom "tidak perlu" kosong.
        foreach (['R', 'T', 'X', 'AA', 'AD', 'AE', 'AH', 'AI', 'AJ', 'AL', 'AP', 'AR', 'AS', 'AT', 'AU', 'AV', 'AW'] as $column) {
            $this->assertNull($this->cell($row, $column), $column);
        }
    }

    public function test_cancelled_and_unpaid_orders_are_left_out_but_stay_stored(): void
    {
        $kept = $this->paidOrder('Dipertahankan');
        $cancelled = $this->paidOrder('Dibatalkan');
        $cancelled->forceFill(['status' => Order::CANCELLED, 'payment_status' => 'refund_pending', 'cancelled_at' => '2026-11-11 03:00:00'])->save();

        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 1])->assertOk();
        $this->withHeader('Idempotency-Key', 'key-'.str()->random(20))->postJson('/api/v1/checkout', [
            'customer_name' => 'Belum Bayar', 'customer_email' => 'x@example.com', 'customer_phone' => '081234567891',
            'address' => ['street' => 'Jl. Uji 2', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt', 'service' => 'ez',
        ])->assertCreated();

        $owner = $this->staff('owner');
        $rows = $this->export($owner);

        $this->assertCount(2, $rows);
        $this->assertSame('Dipertahankan', $this->cell($rows[1], 'A'));
        $this->assertSame($kept->order_number, $this->cell($rows[1], 'G'));
        $this->assertDatabaseHas('orders', ['id' => $cancelled->id, 'status' => Order::CANCELLED]); // tetap tersimpan

        // Pesanan batal tetap tampil di data terpisah (rekap Laporan).
        $report = $this->actingAs($owner)->getJson('/api/v1/admin/reports?from=2026-11-01&to=2026-11-30&group=month')->assertOk()->json('data.cancellations');
        $this->assertSame(1, collect($report['rows'])->firstWhere('label', 'Nov 2026')['paid'] ?? 1);
        $this->assertSame(1, $report['pending']['count']);
    }

    public function test_range_follows_jakarta_time(): void
    {
        $this->paidOrder('Awal November', paidAt: '2026-10-31 17:00:00'); // 00:00 WIB 1 Nov
        $this->paidOrder('Akhir Oktober', paidAt: '2026-10-31 16:59:59'); // 23:59 WIB 31 Okt
        $this->paidOrder('Akhir November', paidAt: '2026-11-30 16:59:59'); // 23:59 WIB 30 Nov
        $this->paidOrder('Awal Desember', paidAt: '2026-11-30 17:00:00'); // 00:00 WIB 1 Des

        $owner = $this->staff('owner');
        $nov = array_map(fn ($r) => $this->cell($r, 'A'), array_slice($this->export($owner), 1));
        $oct = array_map(fn ($r) => $this->cell($r, 'A'), array_slice($this->export($owner, ['from' => '2026-10-01', 'to' => '2026-10-31']), 1));

        $this->assertEqualsCanonicalizing(['Awal November', 'Akhir November'], $nov);
        $this->assertSame(['Akhir Oktober'], $oct);
    }

    public function test_discounts_and_vouchers_are_not_counted_twice(): void
    {
        $order = $this->paidOrder();
        $variant = $order->items->first()->variant;
        // Harga normal 60.000, harga jual 50.000, diskon produk 10% sehingga harga akhir 45.000; voucher Rp10.000 di tingkat pesanan.
        $variant->forceFill(['compare_at_price' => 60000])->save();
        $variant->discounts()->create(['audience' => 'all', 'type' => 'percent', 'value' => 10, 'is_active' => true]);
        $order->items->first()->forceFill(['unit_price' => 45000, 'line_total' => 90000])->save();
        $order->forceFill(['discount_total' => 10000, 'voucher_code' => 'HEMAT10'])->save();

        $row = $this->export($this->staff('owner'))[1];

        $this->assertSame(50000, $this->cell($row, 'U'));
        $this->assertEqualsWithDelta(16.67, $this->cell($row, 'V'), 0.001);
        $this->assertSame(60000, $this->cell($row, 'W'));
        $this->assertEqualsWithDelta(10.0, $this->cell($row, 'Z'), 0.001); // diskon produk bertipe persen
        $this->assertNull($this->cell($row, 'Y')); // tidak diisi ganda
        $this->assertSame(10000, $this->cell($row, 'AF')); // hanya potongan voucher
        $this->assertNull($this->cell($row, 'AG'));
        $this->assertSame('Voucher: HEMAT10', $this->cell($row, 'K'));

        // Diskon produk bertipe nominal masuk Y (rupiah per satuan), bukan Z.
        $variant->discounts()->update(['type' => 'fixed', 'value' => 5000]);
        $order->items->first()->forceFill(['unit_price' => 45000])->save();
        $row = $this->export($this->staff('owner'))[1];
        $this->assertSame(5000, $this->cell($row, 'Y'));
        $this->assertNull($this->cell($row, 'Z'));
    }

    public function test_transaction_fee_per_payment_type_includes_vat(): void
    {
        $service = app(SalesExportService::class);

        $this->assertSame(4440, $service->fee('bank_transfer', 100000)['amount']); // 4.000 + PPN 11%
        $this->assertSame(4440, $service->fee('echannel', 100000)['amount']);
        $this->assertSame(777, $service->fee('qris', 100000)['amount']); // 0,7% = 700, + PPN
        $this->assertSame(777, $service->fee('other_qris', 100000)['amount']);
        $this->assertSame(5439, $service->fee('credit_card', 100000)['amount']); // 2,9% + 2.000 = 4.900, + PPN
        $this->assertNull($service->fee('gopay', 100000)); // tidak dipakai di Tuffero
        $this->assertNull($service->fee('shopeepay', 100000));
        $this->assertNull($service->fee(null, 100000));

        $this->paidOrder(type: 'gopay');
        $summary = $this->actingAs($this->staff('owner'))->getJson('/api/v1/admin/reports/sales-export/summary?from=2026-11-01&to=2026-11-30')->assertOk();
        $this->assertSame(['orders' => 1, 'rows' => 1, 'no_fee' => 1], $summary->json('data'));
    }

    public function test_customer_text_that_looks_like_a_formula_stays_plain_text(): void
    {
        $this->paidOrder('=HYPERLINK("http://jahat.example","klik")');
        $this->paidOrder('@SUM(1+1)');

        $names = array_map(fn ($r) => $this->cell($r, 'A'), array_slice($this->export($this->staff('owner')), 1));

        // Apa adanya (tanpa apostrof) dan tetap teks; pemeriksaan XML di bawah memastikan tidak ada sel rumus.
        $this->assertEqualsCanonicalizing(['=HYPERLINK("http://jahat.example","klik")', '@SUM(1+1)'], $names);

        $path = $this->actingAs($this->staff('owner'))->get('/api/v1/admin/reports/sales-export?from=2026-11-01&to=2026-11-30')->assertOk()->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);
        $this->assertStringNotContainsString('<f>', $xml);
    }

    public function test_only_roles_with_the_export_permission_can_use_it(): void
    {
        $this->paidOrder();
        $url = '/api/v1/admin/reports/sales-export?from=2026-11-01&to=2026-11-30';

        foreach (['marketing', 'store-admin', 'customer-service', 'warehouse'] as $role) {
            $this->actingAs($this->staff($role))->getJson($url)->assertForbidden();
            $this->actingAs($this->staff($role))->getJson('/api/v1/admin/reports/sales-export/summary?from=2026-11-01&to=2026-11-30')->assertForbidden();
        }

        foreach (['owner', 'super-admin'] as $role) {
            $this->assertTrue($this->staff($role)->can('reports.export'), $role);
        }

        // Akun tanpa MFA terdaftar ditolak walau berizin.
        $noMfa = User::factory()->create(['mfa_totp_confirmed_at' => null]);
        $noMfa->assignRole('owner');
        $this->actingAs($noMfa)->getJson($url)->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertUnauthorized();
    }

    public function test_range_is_validated(): void
    {
        $owner = $this->staff('owner');

        $this->actingAs($owner)->getJson('/api/v1/admin/reports/sales-export/summary?from=2026-11-30&to=2026-11-01')->assertUnprocessable();
        $this->actingAs($owner)->getJson('/api/v1/admin/reports/sales-export/summary?from=2025-01-01&to=2026-11-30')->assertUnprocessable();
        $this->actingAs($owner)->getJson('/api/v1/admin/reports/sales-export/summary?from=besok&to=2026-11-30')->assertUnprocessable();
    }

    public function test_download_is_audited_without_customer_data_and_leaves_no_file_behind(): void
    {
        $this->paidOrder('Rahasia Pelanggan');
        $owner = $this->staff('owner');

        $this->export($owner);

        $audit = AuditLog::where('action', 'report.sales_export')->firstOrFail();
        $this->assertSame($owner->id, $audit->actor_user_id);
        $this->assertSame(['from' => '2026-11-01', 'to' => '2026-11-30', 'rows' => 1], $audit->changes);
        $this->assertStringNotContainsString('Rahasia Pelanggan', json_encode($audit->toArray()));
        $this->assertEmpty(glob(storage_path('app/public').'/*sales*'));
    }
}
