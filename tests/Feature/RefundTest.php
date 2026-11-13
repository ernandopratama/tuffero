<?php

namespace Tests\Feature;

use App\Mail\CustomerServiceMessage;
use App\Models\AuditLog;
use App\Models\CustomerEmail;
use App\Models\FollowUp;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Refund;
use App\Models\ReplyTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReplyTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'budi@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $product = Product::create(['name' => 'Nastar', 'slug' => 'nastar', 'is_published' => true]);
        $variant = $product->variants()->create(['sku' => 'NAS-01', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);
        InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->value('id'), 'product_variant_id' => $variant->id, 'quantity' => 100]);
    }

    private function staff(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now(), ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    /** Pesanan yang sudah diterima pelanggan `$daysAgo` hari lalu. */
    private function received(int $quantity = 2, float $daysAgo = 1, string $status = Order::COMPLETED, array $extra = [], int $discount = 0): Order
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => $quantity])->assertOk();

        foreach ($extra as $sku => $qty) {
            $this->postJson('/api/v1/cart/items', ['sku' => $sku, 'quantity' => $qty])->assertOk();
        }

        $ref = $this->withHeader('Idempotency-Key', 'key-'.str()->random(20))->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi Uji',
            'customer_email' => self::EMAIL,
            'customer_phone' => '081234567890',
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt',
            'service' => 'ez',
        ])->assertCreated()->json('data.public_ref');

        $order = Order::where('public_ref', $ref)->firstOrFail();
        $order->forceFill(['status' => $status, 'payment_status' => 'paid', 'paid_at' => now()->subDays($daysAgo + 2), 'discount_total' => $discount])->save();
        $order->shipment()->create(['provider' => 'manual', 'courier_code' => 'jnt', 'service_code' => 'ez', 'waybill_id' => 'RESI'.$order->id, 'status' => 'delivered', 'shipping_fee' => 10000, 'shipped_at' => now()->subDays($daysAgo + 1), 'delivered_at' => now()->subDays($daysAgo)]);

        return $order->fresh();
    }

    /** @param array<string, mixed> $override */
    private function body(Order $order, array $override = []): array
    {
        return [
            'order_number' => $order->order_number,
            'contact' => self::EMAIL,
            'reason_type' => 'damaged',
            'description' => 'Kemasan penyok dan kue hancur saat tiba.',
            'contact_preference' => 'whatsapp',
            'items' => [['id' => $order->items->first()->id, 'quantity' => 1]],
            'terms' => true,
            ...$override,
        ];
    }

    private function submit(Order $order, array $override = [], ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? 'rf-'.str()->random(16))->postJson('/api/v1/refunds', $this->body($order, $override));
    }

    private function filed(Order $order, array $override = []): Refund
    {
        $this->submit($order, $override)->assertCreated();

        return Refund::latest('id')->firstOrFail();
    }

    private function act(User $user, Refund $refund, string $action, array $body = [])
    {
        return $this->actingAs($user)->postJson("/api/v1/admin/refunds/{$refund->id}/{$action}", $body);
    }

    // ---------- pelanggan ----------

    public function test_the_refund_page_is_served_in_both_languages_and_links_the_terms(): void
    {
        $this->get('/ajukan-refund')->assertOk()->assertSee('Ajukan refund atau retur')->assertSee('3 hari kalender')->assertSee('href="/kontak#syarat"', false)->assertSee('content="noindex, nofollow"', false);
        $this->get('/ajukan-refund?lang=en')->assertOk()->assertSee('Request a refund or return')->assertSee('3 calendar days');
        $this->get('/cek-pesanan')->assertOk()->assertSee('/ajukan-refund', false);
    }

    public function test_lookup_hides_whether_an_order_exists(): void
    {
        $order = $this->received();

        $wrongContact = $this->postJson('/api/v1/refunds/lookup', ['order_number' => $order->order_number, 'contact' => 'orang.lain@example.com']);
        $unknownOrder = $this->postJson('/api/v1/refunds/lookup', ['order_number' => 'TF0000-000000', 'contact' => self::EMAIL]);

        $wrongContact->assertUnprocessable()->assertJsonValidationErrors('order');
        $this->assertSame($wrongContact->json('errors'), $unknownOrder->json('errors'));
        $this->assertSame($wrongContact->json('message'), $unknownOrder->json('message'));
    }

    public function test_lookup_returns_claimable_items_and_both_windows_for_the_owner(): void
    {
        $order = $this->received(3, 1);

        foreach ([self::EMAIL, '0812-3456-7890'] as $contact) {
            $data = $this->postJson('/api/v1/refunds/lookup', ['order_number' => strtolower($order->order_number), 'contact' => $contact])->assertOk()->json('data');

            $this->assertSame(3, $data['items'][0]['available']);
            $this->assertSame(50000, $data['items'][0]['unit_price']);
            $this->assertTrue($data['windows']['claim']['open']);
            $this->assertTrue($data['windows']['return']['open']);
        }
    }

    public function test_an_order_not_yet_received_has_no_window(): void
    {
        $order = $this->received(1, 1, Order::SHIPPED);

        $this->assertNull($this->postJson('/api/v1/refunds/lookup', ['order_number' => $order->order_number, 'contact' => self::EMAIL])->assertOk()->json('data.windows'));
        $this->submit($order)->assertUnprocessable()->assertJsonValidationErrors(['order' => 'not_received']);
        $this->assertSame(0, Refund::count());
    }

    public function test_the_refund_value_is_what_the_customer_paid_after_their_share_of_the_voucher(): void
    {
        $second = Product::create(['name' => 'Pia', 'slug' => 'pia', 'is_published' => true]);
        $variant = $second->variants()->create(['sku' => 'PIA-01', 'name' => 'Standar', 'price' => 30000, 'weight_gram' => 300]);
        InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->value('id'), 'product_variant_id' => $variant->id, 'quantity' => 100]);

        // 2 x Rp50.000 dan 1 x Rp30.000 = Rp130.000, voucher Rp13.000: dibayar Rp117.000 untuk barang.
        $order = $this->received(2, 1, Order::COMPLETED, ['PIA-01' => 1], 13000);
        [$nastar, $pia] = [$order->items->firstWhere('sku_snapshot', 'NAS-01'), $order->items->firstWhere('sku_snapshot', 'PIA-01')];

        $lookup = $this->postJson('/api/v1/refunds/lookup', ['order_number' => $order->order_number, 'contact' => self::EMAIL])->assertOk()->json('data');
        $this->assertTrue($lookup['has_discount']);
        $this->assertSame([90000, 27000], collect($lookup['items'])->pluck('paid_total')->all());

        // Satu dari dua Nastar: separuh dari yang dibayar untuk barisnya (Rp90.000), bukan Rp50.000.
        $this->submit($order, ['items' => [['id' => $nastar->id, 'quantity' => 1]]])->assertCreated();
        $line = Refund::firstOrFail()->items()->firstOrFail();
        $this->assertSame([45000, 50000, 45000], [Refund::first()->requested_amount, $line->unit_price_snapshot, $line->line_amount]);

        // Sisa barang diklaim semua: total seluruh pengajuan tepat sama dengan yang dibayar untuk barang.
        $this->submit($order, ['items' => [['id' => $nastar->id, 'quantity' => 1], ['id' => $pia->id, 'quantity' => 1]]])->assertCreated();
        $this->assertSame(45000 + 45000 + 27000, (int) Refund::sum('requested_amount'));
        $this->assertSame((int) $order->subtotal - 13000, (int) Refund::sum('requested_amount'));
    }

    public function test_cs_only_notes_are_hidden_from_other_roles_on_orders_and_refunds(): void
    {
        $order = $this->received(3);
        $refund = $this->filed($order);
        $cs = $this->staff('customer-service');
        $secondCs = $this->staff('customer-service');
        $store = $this->staff('store-admin');
        $owner = $this->staff('owner');

        foreach (["/api/v1/admin/orders/{$order->id}/comments", "/api/v1/admin/refunds/{$refund->id}/comments"] as $url) {
            $this->actingAs($cs)->postJson($url, ['body' => 'Pelanggan sensitif, hubungi lewat WA', 'visibility' => 'cs'])->assertCreated()->assertJsonPath('data.visibility', 'cs');
            $this->actingAs($owner)->postJson($url, ['body' => 'Catatan staf biasa'])->assertCreated()->assertJsonPath('data.visibility', 'staff');

            $this->actingAs($cs)->getJson($url)->assertOk()->assertJsonCount(2, 'data.comments')->assertJsonPath('data.can_cs', true);
            foreach ([$owner] as $other) {
                $seen = $this->actingAs($other)->getJson($url)->assertOk()->assertJsonCount(1, 'data.comments')->assertJsonPath('data.can_cs', false);
                $this->assertStringNotContainsString('sensitif', $seen->getContent());
            }

            $this->actingAs($owner)->postJson($url, ['body' => 'Coba tulis catatan CS', 'visibility' => 'cs'])->assertForbidden();
            $this->actingAs($cs)->postJson($url, ['body' => 'x', 'visibility' => 'rahasia'])->assertUnprocessable();
            $this->actingAs($cs)->postJson($url, ['body' => 'Tag bukan CS', 'visibility' => 'cs', 'mentions' => [$store->id]])->assertUnprocessable();
            $this->actingAs($cs)->postJson($url, ['body' => 'Tag rekan CS', 'visibility' => 'cs', 'mentions' => [$secondCs->id]])->assertCreated();
        }

        $this->assertSame(4, AuditLog::where('action', 'comment.cs_created')->count());
        $this->assertStringNotContainsString('sensitif', AuditLog::where('action', 'comment.cs_created')->get()->toJson());
        $this->actingAs($this->staff('marketing'))->getJson("/api/v1/admin/refunds/{$refund->id}/comments")->assertForbidden();
    }

    public function test_reply_templates_are_managed_only_by_customer_service_and_super_admin(): void
    {
        $this->assertSame(5, ReplyTemplate::count());
        $this->seed(ReplyTemplateSeeder::class);
        $this->assertSame(5, ReplyTemplate::count(), 'seeder tidak menimpa atau menggandakan');

        foreach (['customer-service', 'super-admin'] as $role) {
            $this->actingAs($this->staff($role))->getJson('/api/v1/admin/reply-templates')->assertOk()->assertJsonCount(5, 'data.items');
        }
        foreach (['store-admin', 'owner', 'warehouse', 'marketing'] as $role) {
            $this->actingAs($this->staff($role))->getJson('/api/v1/admin/reply-templates')->assertForbidden();
            $this->actingAs($this->staff($role))->postJson('/api/v1/admin/reply-templates', ['title' => 'x', 'body' => 'y'])->assertForbidden();
        }

        $cs = $this->staff('customer-service');
        $id = $this->actingAs($cs)->postJson('/api/v1/admin/reply-templates', ['title' => 'Uji', 'body' => 'Halo {nama}', 'scope' => 'any'])->assertCreated()->json('data.id');
        $this->actingAs($cs)->patchJson("/api/v1/admin/reply-templates/{$id}", ['is_active' => false])->assertOk();
        $this->actingAs($cs)->postJson('/api/v1/admin/reply-templates', ['title' => 'x', 'body' => 'y', 'scope' => 'semua'])->assertUnprocessable();
        $this->actingAs($cs)->postJson('/api/v1/admin/reply-templates', ['title' => 'x', 'body' => str_repeat('a', 1001)])->assertUnprocessable();
        $this->actingAs($this->staff('customer-service', ['mfa_totp_confirmed_at' => null]))->postJson('/api/v1/admin/reply-templates', ['title' => 'x', 'body' => 'y'])->assertForbidden();

        $this->assertSame(1, AuditLog::where('action', 'reply_template.created')->count());
        $this->assertSame(1, AuditLog::where('action', 'reply_template.updated')->count());
        $this->assertFalse(ReplyTemplate::findOrFail($id)->is_active);
    }

    public function test_reply_templates_fill_variables_from_server_data_and_match_the_context(): void
    {
        $order = $this->received(2);
        $refund = $this->filed($order);
        $cs = $this->staff('customer-service');
        ReplyTemplate::create(['title' => 'Salah ketik', 'body' => 'Halo {nama} {tidak_dikenal}', 'scope' => 'order', 'sort_order' => 99]);
        ReplyTemplate::where('title', 'Info status dan resi')->update(['is_active' => false]);

        $forOrder = collect($this->actingAs($cs)->getJson("/api/v1/admin/orders/{$order->id}/reply-templates")->assertOk()->json('data.items'));
        $this->assertSame('Halo Budi Uji {tidak_dikenal}', $forOrder->firstWhere('title', 'Salah ketik')['text'], 'variabel tak dikenal tidak diganti');
        $this->assertNull($forOrder->firstWhere('title', 'Info status dan resi'), 'template nonaktif tidak muncul');
        $this->assertNull($forOrder->firstWhere('title', 'Minta video unboxing'), 'template refund tidak muncul di pesanan');

        $forRefund = collect($this->actingAs($cs)->getJson("/api/v1/admin/refunds/{$refund->id}/reply-templates")->assertOk()->json('data.items'));
        $video = $forRefund->firstWhere('title', 'Minta video unboxing')['text'];
        $this->assertStringContainsString('Budi Uji', $video);
        $this->assertStringContainsString($refund->refund_number, $video);
        $this->assertStringContainsString($order->order_number, $video);
        $this->assertStringNotContainsString('{', $video);
        $this->assertNull($forRefund->firstWhere('title', 'Salah ketik'));

        // Isi tidak membawa email, HP, atau alamat pelanggan.
        $this->assertStringNotContainsString(self::EMAIL, $forRefund->toJson());
        $this->assertStringNotContainsString('081234567890', $forRefund->toJson());

        $this->actingAs($this->staff('store-admin'))->getJson("/api/v1/admin/orders/{$order->id}/reply-templates")->assertForbidden();
        $this->actingAs($this->staff('owner'))->getJson("/api/v1/admin/refunds/{$refund->id}/reply-templates")->assertForbidden();
    }

    public function test_quick_search_finds_records_for_cs_without_leaking_contact_data(): void
    {
        $order = $this->received(2);
        $refund = $this->filed($order);
        $cs = $this->staff('customer-service');
        $search = fn (string $q) => $this->actingAs($cs)->getJson('/api/v1/admin/quick-search?q='.urlencode($q));

        foreach ([$order->order_number, 'budi uji', '081234567890', '6281234567890', 'RESI'.$order->id] as $term) {
            $groups = collect($search($term)->assertOk()->json('data.groups'))->keyBy('key');
            $this->assertSame($order->order_number, $groups['orders']['rows'][0]['title'], "cari: {$term}");
        }

        $groups = collect($search($refund->refund_number)->assertOk()->json('data.groups'))->keyBy('key');
        $this->assertSame($refund->refund_number, $groups['refunds']['rows'][0]['title']);

        $json = $search('budi')->assertOk()->getContent();
        $this->assertStringNotContainsString(self::EMAIL, $json);
        $this->assertStringNotContainsString('081234567890', $json);

        $this->assertSame([], $search('zzzzzz')->assertOk()->json('data.groups'));
        $search('ab')->assertUnprocessable();
        $this->actingAs($this->staff('owner'))->getJson('/api/v1/admin/quick-search?q=budi')->assertForbidden();
        $this->actingAs($this->staff('warehouse'))->getJson('/api/v1/admin/quick-search?q=budi')->assertForbidden();
    }

    public function test_follow_ups_are_cs_only_validated_and_shown_on_the_dashboard(): void
    {
        $order = $this->received(2);
        $refund = $this->filed($order);
        $cs = $this->staff('customer-service');
        $owner = $this->staff('owner');
        $tomorrow = now()->addDay()->toDateString();

        $this->actingAs($cs)->postJson("/api/v1/admin/orders/{$order->id}/follow-ups", ['note' => 'Hubungi lagi soal paket', 'due_on' => $tomorrow])->assertCreated()->assertJsonPath('data.created_by', $cs->name);
        $this->actingAs($cs)->postJson("/api/v1/admin/refunds/{$refund->id}/follow-ups", ['note' => 'Tunggu video', 'due_on' => now()->toDateString()])->assertCreated();

        $this->actingAs($cs)->postJson("/api/v1/admin/orders/{$order->id}/follow-ups", ['note' => 'x', 'due_on' => now()->subDay()->toDateString()])->assertUnprocessable();
        $this->actingAs($cs)->postJson("/api/v1/admin/orders/{$order->id}/follow-ups", ['note' => 'x', 'due_on' => now()->addDays(120)->toDateString()])->assertUnprocessable();
        $this->actingAs($cs)->postJson("/api/v1/admin/orders/{$order->id}/follow-ups", ['note' => str_repeat('a', 201), 'due_on' => $tomorrow])->assertUnprocessable();

        $this->actingAs($owner)->getJson("/api/v1/admin/orders/{$order->id}/follow-ups")->assertForbidden();
        $this->actingAs($owner)->postJson("/api/v1/admin/orders/{$order->id}/follow-ups", ['note' => 'x', 'due_on' => $tomorrow])->assertForbidden();
        $this->actingAs($this->staff('customer-service', ['mfa_totp_confirmed_at' => null]))->postJson("/api/v1/admin/orders/{$order->id}/follow-ups", ['note' => 'x', 'due_on' => $tomorrow])->assertForbidden();

        $this->actingAs($cs)->getJson("/api/v1/admin/orders/{$order->id}/follow-ups")->assertOk()->assertJsonCount(1, 'data.items');

        $list = fn () => collect($this->actingAs($cs)->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.lists'))->firstWhere('key', 'followup_due');
        $this->assertCount(2, $list()['rows']);
        $this->assertSame('Tunggu video', $list()['rows'][0]['title'], 'yang paling mendesak lebih dulu');
        $this->assertSame(['cari' => $refund->refund_number], $list()['rows'][0]['query']);
        $this->assertNull(collect($this->actingAs($owner)->getJson('/api/v1/admin/dashboard')->json('data.lists'))->firstWhere('key', 'followup_due'));

        $first = FollowUp::where('note', 'Tunggu video')->firstOrFail();
        $this->actingAs($cs)->postJson("/api/v1/admin/follow-ups/{$first->id}/done")->assertOk();
        $this->actingAs($cs)->postJson("/api/v1/admin/follow-ups/{$first->id}/done")->assertOk();
        $this->actingAs($owner)->postJson("/api/v1/admin/follow-ups/{$first->id}/done")->assertForbidden();

        $this->assertCount(1, $list()['rows']);
        $this->assertSame(1, AuditLog::where('action', 'follow_up.done')->count(), 'selesai dua kali tetap satu catatan audit');
        $this->assertSame(2, AuditLog::where('action', 'follow_up.created')->count());
    }

    public function test_dashboard_shows_store_hours_for_cs_only(): void
    {
        $cs = $this->staff('customer-service');
        $text = function (string $at) use ($cs) {
            $this->travelTo(Carbon::parse($at, 'Asia/Jakarta'));

            return $this->actingAs($cs)->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.store_hours');
        };

        $this->assertSame(['open' => true, 'text' => 'Toko buka, tutup pukul 16.00 WIB'], $text('2026-11-16 10:00')); // Senin
        $this->assertSame(['open' => false, 'text' => 'Toko tutup, buka hari ini pukul 09.00 WIB'], $text('2026-11-16 08:00'));
        $this->assertSame(['open' => false, 'text' => 'Toko tutup, buka besok pukul 09.00 WIB'], $text('2026-11-16 16:00'));
        $this->assertSame(['open' => false, 'text' => 'Toko tutup, buka Senin pukul 09.00 WIB'], $text('2026-11-14 17:00')); // Sabtu sore
        $this->assertSame(['open' => false, 'text' => 'Toko tutup, buka besok pukul 09.00 WIB'], $text('2026-11-15 12:00')); // Minggu

        $owner = $this->actingAs($this->staff('owner'))->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');
        $this->assertNull($owner['store_hours']);
        $this->assertSame([], $owner['service_stats']);
    }

    public function test_dashboard_service_stats_summarise_the_last_30_days_without_customer_data(): void
    {
        $order = $this->received(2);
        $refund = $this->filed($order);
        $cs = $this->staff('customer-service');
        $tiles = fn () => collect($this->actingAs($cs)->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.service_stats'))->keyBy('key');

        $this->assertSame('-', $tiles()['refund_first']['value'], 'belum ada data');
        $this->assertSame('-', $tiles()['refund_on_time']['value']);

        $refund->forceFill(['created_at' => now()->subHours(5), 'contacted_at' => now()->subHours(3), 'status' => Refund::REFUNDED, 'refunded_at' => now(), 'due_at' => now()->addDay()])->save();

        $this->assertSame('1', $tiles()['refund_done']['value']);
        $this->assertSame('2,0 jam', $tiles()['refund_first']['value']);
        $this->assertSame('100%', $tiles()['refund_on_time']['value']);

        // Selesai lewat batas waktu dihitung terlambat; yang lebih lama dari 30 hari tidak ikut.
        $refund->forceFill(['due_at' => now()->subDay()])->save();
        $this->assertSame('0%', $tiles()['refund_on_time']['value']);
        $refund->forceFill(['refunded_at' => now()->subDays(31)])->save();
        $this->assertSame('0', $tiles()['refund_done']['value']);

        $json = json_encode($tiles()->all());
        $this->assertStringNotContainsString('Budi', $json);
        $this->assertStringNotContainsString(self::EMAIL, $json);
        $this->assertStringNotContainsString('rupiah', $json);
    }

    public function test_cs_sends_email_only_on_demand_to_the_server_side_address_and_it_is_logged(): void
    {
        Mail::fake();
        $order = $this->received(2);
        $refund = $this->filed($order);
        $cs = $this->staff('customer-service');
        $url = "/api/v1/admin/orders/{$order->id}/customer-emails";

        // Membuka riwayat tidak mengirim apa pun; alamat disamarkan.
        $this->actingAs($cs)->getJson($url)->assertOk()->assertJsonPath('data.to', 'b***@example.com');
        Mail::assertNothingSent();

        // Alamat tujuan dari server: kiriman `to` dari browser diabaikan.
        $this->actingAs($cs)->postJson($url, ['subject' => 'Info pesanan', 'text' => "Halo Budi,\nPesanan Anda <b>diproses</b>.", 'to' => 'penyerang@evil.test'])->assertCreated()->assertJsonPath('data.to', 'b***@example.com');
        Mail::assertSent(CustomerServiceMessage::class, function (CustomerServiceMessage $mail) {
            return $mail->hasTo(self::EMAIL) && ! $mail->hasTo('penyerang@evil.test') && str_contains($mail->body, 'Tim Customer Service Tuffero')
                && $mail->hasReplyTo(Setting::where('key', 'contact.admin_email')->value('value'));
        });
        Mail::assertSentCount(1);
        $this->assertStringNotContainsString('<b>', (new CustomerServiceMessage('s', 'x <b>y</b>'))->render(), 'HTML dari pengguna di-escape');

        // Jejak: subjek dan alamat tersamar, tanpa isi email.
        $this->assertSame(1, CustomerEmail::where('status', 'sent')->count());
        $this->assertSame(['Info pesanan'], CustomerEmail::pluck('subject')->all());
        $audit = AuditLog::where('action', 'customer_email.sent')->firstOrFail();
        $this->assertStringNotContainsString('Halo Budi', $audit->toJson());
        $this->assertStringNotContainsString(self::EMAIL, $audit->toJson());

        // Klik ganda, header injection, kosong, dan terlalu panjang ditolak.
        $this->actingAs($cs)->postJson($url, ['subject' => 'Info pesanan', 'text' => 'lagi'])->assertStatus(429);
        $this->actingAs($cs)->postJson($url, ['subject' => "Halo\r\nBcc: x@evil.test", 'text' => 'x'])->assertUnprocessable();
        $this->actingAs($cs)->postJson($url, ['subject' => '', 'text' => 'x'])->assertUnprocessable();
        $this->actingAs($cs)->postJson($url, ['subject' => 'ok', 'text' => str_repeat('a', 2001)])->assertUnprocessable();
        Mail::assertSentCount(1);

        // Refund memakai email pengaju; peran lain, tanpa MFA, atau tanpa izin lihat datanya ditolak.
        $this->actingAs($cs)->postJson("/api/v1/admin/refunds/{$refund->id}/customer-emails", ['subject' => 'Refund', 'text' => 'x'])->assertCreated();
        Mail::assertSentCount(2);
        foreach (['owner', 'store-admin', 'warehouse', 'marketing'] as $role) {
            $this->actingAs($this->staff($role))->postJson($url, ['subject' => 'x', 'text' => 'y'])->assertForbidden();
            $this->actingAs($this->staff($role))->getJson($url)->assertForbidden();
        }
        $this->actingAs($this->staff('customer-service', ['mfa_totp_confirmed_at' => null]))->postJson($url, ['subject' => 'baru', 'text' => 'x'])->assertForbidden();
        Mail::assertSentCount(2);
    }

    public function test_a_failed_email_is_recorded_as_failed_and_reported_without_leaking_the_address(): void
    {
        $order = $this->received(2);
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);

        $response = $this->actingAs($this->staff('customer-service'))->postJson("/api/v1/admin/orders/{$order->id}/customer-emails", ['subject' => 'Info', 'text' => 'Halo'])->assertStatus(502);

        $this->assertStringNotContainsString(self::EMAIL, $response->getContent());
        $this->assertSame(['failed'], CustomerEmail::pluck('status')->all());
        $this->assertSame(1, AuditLog::where('action', 'customer_email.failed')->count());
    }

    public function test_customer_history_links_orders_refunds_by_the_same_contact_without_leaking_it(): void
    {
        $first = $this->received(2);
        $second = $this->received(1);
        $refund = $this->filed($first);
        $cs = $this->staff('customer-service');

        $groups = collect($this->actingAs($cs)->getJson("/api/v1/admin/orders/{$second->id}/customer-history")->assertOk()->json('data.groups'))->keyBy('key');
        $this->assertSame([$first->order_number], array_column($groups['orders']['rows'], 'title'), 'pesanan lain dengan kontak sama, bukan pesanan ini');
        $this->assertSame([$refund->refund_number], array_column($groups['refunds']['rows'], 'title'));

        $fromRefund = collect($this->actingAs($cs)->getJson("/api/v1/admin/refunds/{$refund->id}/customer-history")->assertOk()->json('data.groups'))->keyBy('key');
        $this->assertCount(1, $fromRefund['orders']['rows'], 'pesanan kedua muncul dari sisi refund');
        $this->assertSame([], $fromRefund['refunds']['rows'], 'refund ini sendiri tidak ikut');

        $json = $this->actingAs($cs)->getJson("/api/v1/admin/orders/{$second->id}/customer-history")->getContent();
        $this->assertStringNotContainsString(self::EMAIL, $json);
        $this->assertStringNotContainsString('081234567890', $json);

        // Kontak lain tidak ikut.
        Order::where('id', $second->id)->update(['customer_email' => 'lain@example.com', 'customer_phone' => '089900000000']);
        $alone = collect($this->actingAs($cs)->getJson("/api/v1/admin/orders/{$second->id}/customer-history")->json('data.groups'))->keyBy('key');
        $this->assertSame([], $alone['orders']['rows']);

        foreach (['owner', 'store-admin', 'warehouse', 'marketing'] as $role) {
            $this->actingAs($this->staff($role))->getJson("/api/v1/admin/orders/{$second->id}/customer-history")->assertForbidden();
        }
    }

    public function test_dashboard_lists_problem_shipments_and_low_reviews_for_cs(): void
    {
        $order = $this->received(2);
        $cs = $this->staff('customer-service');
        $list = fn (string $key) => collect($this->actingAs($cs)->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.lists'))->firstWhere('key', $key);

        $this->assertSame([], $list('shipment_problems')['rows']);
        $this->assertSame([], $list('low_reviews')['rows']);

        $order->shipment->forceFill(['status' => 'returned'])->save();
        $row = $list('shipment_problems')['rows'][0];
        $this->assertStringContainsString($order->order_number, $row['title']);
        $this->assertSame('Dikembalikan', $row['chip']['text']);
        $this->assertSame(['cari' => $order->order_number], $row['query']);

        $order->shipment->forceFill(['status' => 'delivered'])->save();
        $this->assertSame([], $list('shipment_problems')['rows'], 'paket normal tidak ikut');

        $product = Product::firstOrFail();
        $item = $order->items->first();
        ProductReview::create(['product_id' => $product->id, 'order_item_id' => $item->id, 'author_name' => 'Budi', 'rating' => 1, 'body' => 'Kecewa', 'is_published' => true]);
        ProductReview::create(['product_id' => $product->id, 'order_item_id' => null, 'author_name' => 'Sari', 'rating' => 5, 'body' => 'Enak', 'is_published' => true]);

        $rows = $list('low_reviews')['rows'];
        $this->assertCount(1, $rows, 'hanya bintang 1 sampai 2');
        $this->assertSame('★ 1', $rows[0]['chip']['text']);
        $this->assertSame(['cari' => $order->order_number], $rows[0]['query']);
    }

    public function test_customer_service_dashboard_shows_the_refund_queue_without_money(): void
    {
        $cs = $this->staff('customer-service');
        $order = $this->received(3);
        $this->submit($order)->assertCreated();
        $refund = Refund::firstOrFail();

        $data = $this->actingAs($cs)->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');

        $this->assertSame(1, collect($data['kpis'])->firstWhere('key', 'refund_pending')['value']);
        $queue = collect($data['lists'])->firstWhere('key', 'refund_queue');
        $this->assertSame($refund->refund_number, $queue['rows'][0]['title']);
        $this->assertSame('AdminRefunds', $queue['rows'][0]['to']);
        $this->assertStringNotContainsString('rupiah', json_encode($data));

        $owner = $this->actingAs($this->staff('owner'))->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');
        $this->assertNull(collect($owner['kpis'])->firstWhere('key', 'refund_pending'));
    }

    public function test_the_customer_files_a_refund_and_the_server_computes_the_amount(): void
    {
        $cs = $this->staff('customer-service');
        $order = $this->received(3);

        $response = $this->submit($order, ['items' => [['id' => $order->items->first()->id, 'quantity' => 2]], 'requested_amount' => 1, 'amount' => 1])->assertCreated();

        $refund = Refund::firstOrFail();
        $this->assertMatchesRegularExpression('/^RF\d{4}-\d{6}$/', $refund->refund_number);
        $this->assertSame(100000, $refund->requested_amount, 'harga dan jumlah dari database, bukan browser');
        $this->assertSame(Refund::SUBMITTED, $refund->status);
        $this->assertSame(self::EMAIL, $refund->requester_email);
        $this->assertSame('awaiting', $refund->evidence_status);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $refund->due_at->timestamp, 5);
        $this->assertSame(1, $refund->items()->count());
        $this->assertSame(['submitted'], $refund->histories()->pluck('to_status')->all());

        $this->assertSame($refund->refund_number, $response->json('data.refund_number'));
        $this->assertStringStartsWith('https://wa.me/', $response->json('data.contact_url'));
        $this->assertStringContainsString(rawurlencode($refund->refund_number), $response->json('data.contact_url'));
        $this->assertContains('refund_submitted', $cs->notifications->pluck('data.event')->all());
    }

    public function test_return_window_is_three_days_and_claim_window_is_seven_days(): void
    {
        $fourDays = $this->received(1, 4);
        $this->submit($fourDays, ['reason_type' => 'return_unopened'])->assertUnprocessable()->assertJsonValidationErrors(['order' => 'window_closed']);
        $this->submit($fourDays, ['reason_type' => 'damaged'])->assertCreated();

        $eightDays = $this->received(1, 8);
        $this->submit($eightDays, ['reason_type' => 'damaged'])->assertUnprocessable()->assertJsonValidationErrors(['order' => 'window_closed']);

        $oneDay = $this->received(1, 1);
        $this->submit($oneDay, ['reason_type' => 'return_unopened'])->assertCreated();
    }

    public function test_the_return_window_follows_the_setting(): void
    {
        Setting::where('key', 'refund.return_window_days')->update(['value' => '5']);

        $this->submit($this->received(1, 4), ['reason_type' => 'return_unopened'])->assertCreated();
        $this->submit($this->received(1, 6), ['reason_type' => 'return_unopened'])->assertUnprocessable();
    }

    public function test_quantity_cannot_exceed_the_order_across_active_refunds_and_frees_up_when_closed(): void
    {
        $order = $this->received(2);

        $first = $this->filed($order);
        $this->submit($order, ['items' => [['id' => $order->items->first()->id, 'quantity' => 2]]])->assertUnprocessable()->assertJsonValidationErrors(['items' => 'quantity_exceeded']);
        $this->submit($order, ['items' => [['id' => $order->items->first()->id, 'quantity' => 1]]])->assertCreated();
        $this->submit($order)->assertUnprocessable();

        $this->act($this->staff('owner'), $first, 'reject', ['reason' => 'Bukti kurang'])->assertOk();
        $this->submit($order)->assertCreated();

        $available = $this->postJson('/api/v1/refunds/lookup', ['order_number' => $order->order_number, 'contact' => self::EMAIL])->json('data.items.0.available');
        $this->assertSame(0, $available);
    }

    public function test_items_must_belong_to_the_order_and_not_repeat(): void
    {
        $order = $this->received(2);
        $other = $this->received(1);
        $line = $order->items->first()->id;

        $this->submit($order, ['items' => [['id' => $other->items->first()->id, 'quantity' => 1]]])->assertUnprocessable()->assertJsonValidationErrors(['items' => 'items_invalid']);
        $this->submit($order, ['items' => [['id' => $line, 'quantity' => 1], ['id' => $line, 'quantity' => 1]]])->assertUnprocessable()->assertJsonValidationErrors(['items' => 'items_invalid']);
        $this->submit($order, ['items' => []])->assertUnprocessable();
        $this->assertSame(0, Refund::count());
    }

    public function test_submitting_twice_with_the_same_key_files_one_refund(): void
    {
        $order = $this->received(2);

        $this->submit($order, [], 'kunci-sama-1234')->assertCreated();
        $this->submit($order, [], 'kunci-sama-1234')->assertOk();

        $this->assertSame(1, Refund::count());

        $other = $this->received(1);
        $this->submit($other, [], 'kunci-sama-1234')->assertUnprocessable()->assertJsonValidationErrors('order');
    }

    public function test_validation_and_ownership_are_enforced_on_submit(): void
    {
        $order = $this->received(2);

        // withHeader menempel pada instance uji, jadi kunci dikosongkan untuk menguji header yang hilang.
        $this->withHeader('Idempotency-Key', '')->postJson('/api/v1/refunds', $this->body($order))->assertUnprocessable();
        $this->submit($order, ['terms' => false])->assertUnprocessable()->assertJsonValidationErrors(['terms' => 'terms_required']);
        $this->submit($order, ['description' => 'pendek'])->assertUnprocessable()->assertJsonValidationErrors(['description' => 'description_short']);
        $this->submit($order, ['reason_type' => 'berubah_pikiran'])->assertUnprocessable()->assertJsonValidationErrors('reason_type');
        $this->submit($order, ['contact_preference' => 'sms'])->assertUnprocessable();
        $this->submit($order, ['contact' => 'orang.lain@example.com'])->assertUnprocessable()->assertJsonValidationErrors(['order' => 'not_found']);
        $this->assertSame(0, Refund::count());

        $this->submit($order, ['description' => '<b>Kue</b> hancur <script>x()</script>saat tiba'])->assertCreated();
        $this->assertSame('Kue hancur x()saat tiba', Refund::firstOrFail()->description);
    }

    // ---------- admin ----------

    public function test_the_full_flow_cs_contacts_checks_video_owner_approves_cs_records_the_transfer(): void
    {
        $cs = $this->staff('customer-service');
        $owner = $this->staff('owner');
        $order = $this->received(2);
        $refund = $this->filed($order);

        $this->act($cs, $refund, 'contacted', ['note' => 'Sudah chat WhatsApp'])->assertOk();
        $this->assertSame(Refund::AWAITING_EVIDENCE, $refund->fresh()->status);

        $this->act($cs, $refund, 'evidence', ['result' => 'insufficient', 'channel' => 'whatsapp', 'note' => 'Resi tidak terlihat'])->assertOk();
        $this->assertSame([Refund::AWAITING_EVIDENCE, 'insufficient'], [$refund->fresh()->status, $refund->fresh()->evidence_status]);

        $this->act($cs, $refund, 'evidence', ['result' => 'received', 'channel' => 'whatsapp', 'note' => 'Video lengkap, resi terlihat'])->assertOk();
        $this->assertSame([Refund::UNDER_REVIEW, 'received'], [$refund->fresh()->status, $refund->fresh()->evidence_status]);
        $this->assertContains('refund_review', $owner->notifications->pluck('data.event')->all());

        $this->act($owner, $refund, 'approve', ['approved_amount' => 45000, 'note' => 'Potong diskon voucher'])->assertOk();
        $refund->refresh();
        $this->assertSame([Refund::APPROVED, 45000, $owner->id], [$refund->status, $refund->approved_amount, $refund->decided_by_user_id]);
        $this->assertContains('refund_approved', $cs->fresh()->notifications->pluck('data.event')->all());

        $this->act($cs, $refund, 'refunded', ['reference' => 'TRF-998877', 'note' => 'Transfer BCA'])->assertOk();
        $refund->refresh();
        $this->assertSame([Refund::REFUNDED, 'manual_transfer', 'TRF-998877', $cs->id], [$refund->status, $refund->refund_method, $refund->refund_reference, $refund->refunded_by_user_id]);
        $this->assertNotNull($refund->refunded_at);

        $this->assertSame(
            ['submitted', 'awaiting_evidence', 'awaiting_evidence', 'under_review', 'approved', 'refunded'],
            $refund->histories()->orderBy('id')->pluck('to_status')->all(),
        );
        $this->assertSame(Order::COMPLETED, $order->fresh()->status, 'status pesanan tidak diubah oleh refund per barang');
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_shipping_is_refunded_only_for_tuffero_mistakes_chosen_by_the_owner_and_once_per_order(): void
    {
        $cs = $this->staff('customer-service');
        $owner = $this->staff('owner');
        $order = $this->received(3);
        $fee = (int) $order->shipping_fee;
        $this->assertGreaterThan(0, $fee);

        $review = function (Refund $refund) use ($cs) {
            $this->act($cs, $refund, 'evidence', ['result' => 'received', 'channel' => 'whatsapp', 'note' => 'Video lengkap'])->assertOk();

            return $refund;
        };
        $detail = fn (Refund $refund) => $this->actingAs($owner)->getJson("/api/v1/admin/refunds/{$refund->id}")->assertOk()->json('data');

        // Barang rusak bukan kesalahan Tuffero: ongkir tidak bisa ikut, dan tidak ditawarkan.
        $damaged = $review($this->filed($order, ['reason_type' => 'damaged']));
        $this->assertFalse($detail($damaged)['shipping_eligible']);
        $this->act($owner, $damaged, 'approve', ['approved_amount' => 50000, 'include_shipping' => true])->assertUnprocessable()->assertJsonValidationErrors('include_shipping');
        $this->assertSame(Refund::UNDER_REVIEW, $damaged->fresh()->status);
        $this->act($owner, $damaged, 'approve', ['approved_amount' => 50000])->assertOk();
        $this->assertSame([0, 50000], [$damaged->fresh()->shipping_amount, $detail($damaged)['total_refund']]);

        // Salah kirim: Pemilik boleh memilih ongkir ikut.
        $wrong = $review($this->filed($order, ['reason_type' => 'wrong_item']));
        $this->assertTrue($detail($wrong)['shipping_eligible']);
        $this->act($owner, $wrong, 'approve', ['approved_amount' => 50000, 'include_shipping' => true])->assertOk();
        $row = $detail($wrong);
        $this->assertSame([$fee, 50000 + $fee], [$row['shipping_amount'], $row['total_refund']]);
        $this->assertStringContainsString('Termasuk ongkir', $wrong->histories()->latest('id')->value('reason'));

        // Ongkir pesanan yang sama tidak bisa dikembalikan dua kali.
        $missing = $review($this->filed($order, ['reason_type' => 'missing_item']));
        $this->assertFalse($detail($missing)['shipping_eligible']);
        $this->act($owner, $missing, 'approve', ['approved_amount' => 50000, 'include_shipping' => true])->assertUnprocessable()->assertJsonValidationErrors('include_shipping');
        $this->act($owner, $missing, 'approve', ['approved_amount' => 50000])->assertOk();
        $this->assertSame(0, $missing->fresh()->shipping_amount);

        // Daftar menampilkan total yang ditransfer.
        $totals = collect($this->actingAs($cs)->getJson('/api/v1/admin/refunds')->json('data.items'))->pluck('total_refund', 'refund_number');
        $this->assertSame(50000 + $fee, $totals[$wrong->refund_number]);
    }

    public function test_shipping_cannot_be_included_twice_or_when_the_order_has_none(): void
    {
        $cs = $this->staff('customer-service');
        $owner = $this->staff('owner');
        $order = $this->received(3);

        $first = $this->filed($order, ['reason_type' => 'wrong_item']);
        $this->act($cs, $first, 'evidence', ['result' => 'received', 'channel' => 'email', 'note' => 'Lengkap'])->assertOk();
        $this->act($owner, $first, 'approve', ['approved_amount' => 50000, 'include_shipping' => true])->assertOk();

        $second = $this->filed($order, ['reason_type' => 'missing_item']);
        $this->act($cs, $second, 'evidence', ['result' => 'received', 'channel' => 'email', 'note' => 'Lengkap'])->assertOk();
        $this->act($owner, $second, 'approve', ['approved_amount' => 50000, 'include_shipping' => true])->assertUnprocessable();

        $free = $this->received(2);
        $free->forceFill(['shipping_fee' => 0])->save();
        $refund = $this->filed($free, ['reason_type' => 'wrong_item']);
        $this->act($cs, $refund, 'evidence', ['result' => 'received', 'channel' => 'email', 'note' => 'Lengkap'])->assertOk();
        $this->assertFalse($this->actingAs($owner)->getJson("/api/v1/admin/refunds/{$refund->id}")->json('data.shipping_eligible'));
        $this->act($owner, $refund, 'approve', ['approved_amount' => 50000, 'include_shipping' => true])->assertUnprocessable();
    }

    public function test_cs_records_the_customer_choice_after_the_video_and_the_finish_follows_it(): void
    {
        $cs = $this->staff('customer-service');
        $owner = $this->staff('owner');
        $order = $this->received(3);

        $refund = $this->filed($order, ['reason_type' => 'wrong_item']);
        $this->act($cs, $refund, 'choice', ['choice' => 'voucher'])->assertUnprocessable()->assertJsonValidationErrors('refund');

        $this->act($cs, $refund, 'evidence', ['result' => 'received', 'channel' => 'whatsapp', 'note' => 'Video lengkap'])->assertOk();
        $this->act($owner, $refund, 'choice', ['choice' => 'voucher'])->assertForbidden();
        $this->act($cs, $refund, 'choice', ['choice' => 'tunai'])->assertUnprocessable()->assertJsonValidationErrors('choice');
        $this->act($cs, $refund, 'choice', ['choice' => 'voucher', 'note' => 'Pelanggan pilih voucher'])->assertOk();

        $detail = fn () => $this->actingAs($owner)->getJson("/api/v1/admin/refunds/{$refund->id}")->json('data');
        $this->assertSame(['voucher', false], [$detail()['customer_choice'], $detail()['shipping_eligible']], 'ongkir hanya untuk pengembalian dana');
        $this->assertStringContainsString('Pilihan pelanggan: voucher', $refund->histories()->latest('id')->value('reason'));

        $this->act($owner, $refund, 'approve', ['approved_amount' => 50000, 'include_shipping' => true])->assertUnprocessable()->assertJsonValidationErrors('include_shipping');
        $this->act($owner, $refund, 'approve', ['approved_amount' => 50000])->assertOk();
        $this->act($cs, $refund, 'refunded', ['reference' => 'VCR-ABC'])->assertOk();

        $refund->refresh();
        $this->assertSame([Refund::REFUNDED, 'voucher', 'VCR-ABC'], [$refund->status, $refund->refund_method, $refund->refund_reference]);
        $this->assertSame('voucher', collect($this->actingAs($cs)->getJson('/api/v1/admin/refunds')->json('data.items'))->firstWhere('id', $refund->id)['refund_method']);

        // Kirim ulang memakai jalur yang sama; pengembalian dana tetap bawaan.
        $resend = $this->filed($order, ['reason_type' => 'damaged']);
        $this->act($cs, $resend, 'evidence', ['result' => 'received', 'channel' => 'email', 'note' => 'Lengkap'])->assertOk();
        $this->assertSame('refund', $resend->fresh()->customer_choice);
        $this->act($cs, $resend, 'choice', ['choice' => 'resend'])->assertOk();
        $this->act($owner, $resend, 'approve', ['approved_amount' => 50000])->assertOk();
        $this->act($cs, $resend, 'refunded', ['reference' => 'RESI-KIRIM-ULANG'])->assertOk();
        $this->assertSame('resend', $resend->fresh()->refund_method);

        $transfer = $this->filed($order, ['reason_type' => 'damaged']);
        $this->act($cs, $transfer, 'evidence', ['result' => 'received', 'channel' => 'email', 'note' => 'Lengkap'])->assertOk();
        $this->act($owner, $transfer, 'approve', ['approved_amount' => 50000])->assertOk();
        $this->act($cs, $transfer, 'refunded', ['reference' => 'TRF-1'])->assertOk();
        $this->assertSame('manual_transfer', $transfer->fresh()->refund_method);
    }

    public function test_the_choice_cannot_leave_refund_once_shipping_was_approved(): void
    {
        $cs = $this->staff('customer-service');
        $owner = $this->staff('owner');
        $refund = $this->filed($this->received(2), ['reason_type' => 'missing_item']);

        $this->act($cs, $refund, 'evidence', ['result' => 'received', 'channel' => 'email', 'note' => 'Lengkap'])->assertOk();
        $this->act($owner, $refund, 'approve', ['approved_amount' => 50000, 'include_shipping' => true])->assertOk();

        $this->act($cs, $refund, 'choice', ['choice' => 'voucher'])->assertUnprocessable()->assertJsonValidationErrors('choice');
        $this->assertSame('refund', $refund->fresh()->customer_choice);
        $this->act($cs, $refund, 'choice', ['choice' => 'refund'])->assertOk();
    }

    public function test_permissions_follow_the_role_matrix(): void
    {
        $refund = $this->filed($this->received(2));

        $this->getJson('/api/v1/admin/refunds')->assertUnauthorized();

        foreach (['store-admin', 'warehouse', 'marketing'] as $role) {
            $this->actingAs($this->staff($role))->getJson('/api/v1/admin/refunds')->assertForbidden();
            $this->act($this->staff($role), $refund, 'contacted')->assertForbidden();
        }

        $owner = $this->staff('owner');
        $this->actingAs($owner)->getJson('/api/v1/admin/refunds')->assertOk();
        foreach (['contacted', 'evidence', 'refunded', 'cancel'] as $action) {
            $this->act($owner, $refund, $action, ['note' => 'x', 'result' => 'received', 'channel' => 'whatsapp', 'reference' => 'x'])->assertForbidden();
        }

        $cs = $this->staff('customer-service');
        $this->actingAs($cs)->getJson('/api/v1/admin/refunds')->assertOk();
        foreach (['approve', 'reject'] as $action) {
            $this->act($cs, $refund, $action, ['approved_amount' => 1, 'reason' => 'x'])->assertForbidden();
        }

        $this->act($this->staff('customer-service', ['mfa_totp_confirmed_at' => null]), $refund, 'contacted')->assertForbidden();
        $this->assertSame(Refund::SUBMITTED, $refund->fresh()->status);
    }

    public function test_invalid_transitions_and_amounts_are_refused(): void
    {
        $cs = $this->staff('customer-service');
        $owner = $this->staff('owner');
        $refund = $this->filed($this->received(2));

        $this->act($owner, $refund, 'approve', ['approved_amount' => 10000])->assertUnprocessable();
        $this->act($cs, $refund, 'refunded', ['reference' => 'X'])->assertUnprocessable();

        $this->act($cs, $refund, 'evidence', ['result' => 'received', 'channel' => 'email', 'note' => 'Video lengkap'])->assertOk();
        $this->act($owner, $refund, 'approve', ['approved_amount' => 50001])->assertUnprocessable()->assertJsonValidationErrors('approved_amount');
        $this->act($owner, $refund, 'approve', ['approved_amount' => 0])->assertUnprocessable();
        $this->act($cs, $refund, 'contacted')->assertUnprocessable();
        $this->act($owner, $refund, 'approve', ['approved_amount' => 50000])->assertOk();
        $this->act($owner, $refund, 'approve', ['approved_amount' => 50000])->assertUnprocessable();
        $this->act($owner, $refund, 'reject', ['reason' => 'Terlambat'])->assertUnprocessable();
        $this->act($cs, $refund, 'refunded', [])->assertUnprocessable()->assertJsonValidationErrors('reference');
    }

    public function test_approval_needs_a_complete_video(): void
    {
        $owner = $this->staff('owner');
        $refund = $this->filed($this->received(2));
        $refund->forceFill(['status' => Refund::UNDER_REVIEW])->save();

        $this->act($owner, $refund, 'approve', ['approved_amount' => 10000])->assertUnprocessable()->assertJsonValidationErrors('refund');
    }

    public function test_reject_and_cancel_need_a_reason_and_only_work_before_a_decision(): void
    {
        $owner = $this->staff('owner');
        $cs = $this->staff('customer-service');
        $order = $this->received(2);

        $toReject = $this->filed($order);
        $this->act($owner, $toReject, 'reject', [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->act($owner, $toReject, 'reject', ['reason' => 'Video menunjukkan kemasan utuh'])->assertOk();
        $this->assertSame([Refund::REJECTED, 'Video menunjukkan kemasan utuh'], [$toReject->fresh()->status, $toReject->fresh()->rejection_reason]);
        $this->act($cs, $toReject, 'cancel', ['note' => 'x'])->assertUnprocessable();

        $toCancel = $this->filed($order);
        $this->act($cs, $toCancel, 'cancel', [])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->act($cs, $toCancel, 'cancel', ['note' => 'Pelanggan menarik pengajuan'])->assertOk();
        $this->assertSame(Refund::CANCELLED, $toCancel->fresh()->status);
    }

    public function test_list_search_filter_and_counts(): void
    {
        $cs = $this->staff('customer-service');
        $a = $this->filed($this->received(2));
        $b = $this->filed($this->received(2));
        $this->act($cs, $b, 'contacted')->assertOk();

        $list = fn (string $query = '') => $this->actingAs($cs)->getJson('/api/v1/admin/refunds'.$query)->assertOk()->json('data');

        $this->assertSame(2, $list()['meta']['total']);
        $this->assertEquals(['submitted' => 1, 'awaiting_evidence' => 1], $list()['meta']['counts']);
        $this->assertSame([$b->refund_number], array_column($list('?status=awaiting_evidence')['items'], 'refund_number'));
        $this->assertSame([$a->refund_number], array_column($list('?search='.$a->refund_number)['items'], 'refund_number'));
        $this->assertSame(2, count($list('?search=Budi')['items']));

        $json = json_encode($list());
        $this->assertStringNotContainsString(self::EMAIL, $json);
        $this->assertStringNotContainsString('081234567890', $json);
    }

    public function test_detail_shows_items_contact_and_the_timeline(): void
    {
        $cs = $this->staff('customer-service');
        $refund = $this->filed($this->received(2), ['contact_preference' => 'email']);
        $this->act($cs, $refund, 'contacted', ['note' => 'Sudah dihubungi'])->assertOk();

        $data = $this->actingAs($cs)->getJson("/api/v1/admin/refunds/{$refund->id}")->assertOk()->json('data');

        $this->assertSame(self::EMAIL, $data['requester_email']);
        $this->assertSame('email', $data['contact_preference']);
        $this->assertSame('Nastar', $data['items'][0]['name']);
        $this->assertSame(50000, $data['items'][0]['line_amount']);
        $this->assertSame(['submitted', 'awaiting_evidence'], array_column($data['history'], 'to'));
        $this->assertSame('Sudah dihubungi', $data['history'][1]['reason']);
        $this->assertArrayNotHasKey('ip_address', $data);
        $this->assertArrayNotHasKey('idempotency_key', $data);
    }

    public function test_dashboard_attention_follows_each_role(): void
    {
        $cs = $this->staff('customer-service');
        $owner = $this->staff('owner');
        $refund = $this->filed($this->received(2));
        $attention = fn (User $user) => collect($this->actingAs($user)->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.attention'))->keyBy('key');

        $this->assertSame(1, $attention($cs)['refund_process']['count']);
        $this->assertArrayNotHasKey('refund_decide', $attention($cs)->all());
        $this->assertArrayNotHasKey('refund_process', $attention($owner)->all());

        $this->act($cs, $refund, 'evidence', ['result' => 'received', 'channel' => 'whatsapp', 'note' => 'Lengkap'])->assertOk();
        $this->assertSame(1, $attention($owner)['refund_decide']['count']);
        $this->assertArrayNotHasKey('refund_process', $attention($cs)->all());

        $this->act($owner, $refund, 'approve', ['approved_amount' => 50000])->assertOk();
        $this->assertSame(1, $attention($cs)['refund_payout']['count']);

        $refund->forceFill(['due_at' => now()->subDay()])->save();
        $this->assertSame(1, $attention($owner)['refund_overdue']['count']);
        $this->assertSame('bad', $attention($owner)['refund_overdue']['tone']);
    }
}
