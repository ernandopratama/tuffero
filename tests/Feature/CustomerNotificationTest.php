<?php

namespace Tests\Feature;

use App\Http\Middleware\SetStoreContext;
use App\Jobs\SendCustomerWhatsApp;
use App\Mail\CustomerOrderUpdate;
use App\Models\CustomerNotification;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CancellationService;
use App\Services\CustomerNotifier;
use App\Services\OrderStatusService;
use App\Services\RefundService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Pesan otomatis ke pelanggan untuk pembatalan dan refund: satu pesan per kejadian, setelah commit, kegagalan kirim tidak
 * menggagalkan proses bisnis, dan WhatsApp mati bawaan.
 */
class CustomerNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        config(['services.midtrans.server_key' => 'SB-server', 'services.midtrans.client_key' => 'SB-client', 'services.midtrans.is_production' => false]);

        $product = Product::create(['name' => 'Nastar', 'slug' => 'nastar', 'is_published' => true]);
        $variant = $product->variants()->create(['sku' => 'NAS-01', 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);
        InventoryStock::create(['warehouse_id' => Warehouse::where('code', 'MLG')->value('id'), 'product_variant_id' => $variant->id, 'quantity' => 20]);
    }

    private function order(?string $locale = null): Order
    {
        $this->postJson('/api/v1/cart/items', ['sku' => 'NAS-01', 'quantity' => 2])->assertOk();

        if ($locale) {
            // Permintaan JSON di tes hanya membawa cookie bila withCredentials; browser selalu mengirimnya (origin sama).
            $this->withCredentials()->withUnencryptedCookie(SetStoreContext::COOKIE, $locale);
        }

        $ref = $this->withHeader('Idempotency-Key', 'key-'.str()->random(20))->postJson('/api/v1/checkout', [
            'customer_name' => 'Budi Uji', 'customer_email' => 'budi@example.com', 'customer_phone' => '081234567890',
            'address' => ['street' => 'Jl. Rahasia 99', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt', 'service' => 'ez',
        ])->assertCreated()->json('data.public_ref');

        return Order::where('public_ref', $ref)->firstOrFail();
    }

    private function paidOrder(): Order
    {
        $order = $this->order();
        app(OrderStatusService::class)->markPaid($order);

        return $order->fresh();
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function validatedRequest(Order $order): OrderCancellationRequest
    {
        $service = app(CancellationService::class);
        $request = $service->submit($order, ['reason' => 'Salah pesan', 'channel' => 'whatsapp']);

        return $service->validateRequest($request, $this->staff('customer-service'));
    }

    /** @return list<string> */
    private function queuedKeys(): array
    {
        return Mail::queued(CustomerOrderUpdate::class)->map(fn (CustomerOrderUpdate $mail) => $mail->messageKey)->values()->all();
    }

    private function completedRefund(Order $order, string $key = 'refund-key-1'): Refund
    {
        $order->forceFill(['status' => Order::COMPLETED])->save();

        return app(RefundService::class)->submit([
            'order_number' => $order->order_number, 'contact' => 'budi@example.com', 'reason_type' => 'damaged', 'description' => 'Kemasan penyok',
            'contact_preference' => 'whatsapp', 'items' => [['id' => $order->items()->value('id'), 'quantity' => 1]],
        ], $key, '127.0.0.1', 'test')['refund'];
    }

    public function test_unpaid_self_cancel_sends_one_email_with_only_safe_fields(): void
    {
        Mail::fake();
        $order = $this->order();

        app(CancellationService::class)->cancelUnpaid($order);

        Mail::assertQueuedCount(1);
        Mail::assertQueued(CustomerOrderUpdate::class, fn (CustomerOrderUpdate $mail) => $mail->hasTo('budi@example.com')
            && $mail->messageKey === 'cancelled_unpaid' && $mail->orderNumber === $order->order_number && $mail->locale === 'id');

        $html = (new CustomerOrderUpdate('cancelled_unpaid', $order->order_number))->render();
        $this->assertStringContainsString($order->order_number, $html);
        $this->assertStringNotContainsString('Jl. Rahasia', $html);
        $this->assertStringNotContainsString('budi@example.com', $html);
    }

    public function test_paid_cancellation_flow_sends_one_email_per_customer_step(): void
    {
        Mail::fake();
        $request = $this->validatedRequest($this->paidOrder());
        $service = app(CancellationService::class);

        $approved = $service->approveByStore($request, $this->staff('store-admin'));
        $service->markRefunded($approved, $this->staff('store-admin'));

        // Diajukan, disetujui (dana menunggu), dana dikembalikan; validasi CS tidak mengirim pesan.
        $this->assertSame(['cancellation_submitted', 'cancellation_refund_pending', 'cancellation_refunded'], $this->queuedKeys());
    }

    public function test_unpaid_cancellation_approved_by_store_uses_the_no_refund_message(): void
    {
        Mail::fake();
        $request = $this->validatedRequest($this->order());

        app(CancellationService::class)->approveByStore($request, $this->staff('store-admin'));

        $this->assertSame(['cancellation_submitted', 'cancellation_approved'], $this->queuedKeys());
    }

    public function test_rejection_sends_one_email_without_the_staff_reason(): void
    {
        Mail::fake();
        $request = $this->validatedRequest($this->paidOrder());

        app(CancellationService::class)->reject($request, 'Catatan internal gudang', $this->staff('store-admin'));

        $this->assertSame(['cancellation_submitted', 'cancellation_rejected'], $this->queuedKeys());
        $mail = Mail::queued(CustomerOrderUpdate::class)->last();
        $this->assertStringNotContainsString('Catatan internal', $mail->render());
    }

    public function test_auto_refund_on_approval_sends_only_the_refunded_message(): void
    {
        Mail::fake();
        Setting::where('key', 'cancellation.auto_refund_enabled')->update(['value' => '1']);
        Http::fake(['api.sandbox.midtrans.com/v2/*/refund' => Http::response(['status_code' => '200', 'transaction_status' => 'refund'])]);
        $order = $this->paidOrder();
        PaymentEvent::create(['order_id' => $order->id, 'transaction_id' => 'trx-1', 'transaction_status' => 'settlement', 'payment_type' => 'qris', 'gross_amount' => $order->total, 'payload' => []]);
        $request = $this->validatedRequest($order);

        $approved = app(CancellationService::class)->approveByStore($request, $this->staff('store-admin'));

        $this->assertSame(OrderCancellationRequest::REFUNDED, $approved->status);
        $this->assertSame(['cancellation_submitted', 'cancellation_refunded'], $this->queuedKeys());
    }

    public function test_refund_flow_sends_one_email_per_step_and_a_replayed_submit_sends_nothing(): void
    {
        Mail::fake();
        $order = $this->paidOrder();
        $refund = $this->completedRefund($order);
        $this->completedRefund($order); // kiriman ulang dengan Idempotency-Key yang sama
        $service = app(RefundService::class);
        $cs = $this->staff('customer-service');

        $service->recordEvidence($refund, 'received', 'whatsapp', 'Video lengkap', $cs);
        $service->approve($refund, $refund->requested_amount, false, null, $this->staff('owner'));
        $service->markRefunded($refund, 'TRF-001', null, $cs);

        $this->assertSame(['refund_submitted', 'refund_approved', 'refund_paid_manual_transfer'], $this->queuedKeys());
        $this->assertSame($refund->refund_number, Mail::queued(CustomerOrderUpdate::class)->first()->reference);
    }

    public function test_refund_rejection_sends_one_email(): void
    {
        Mail::fake();
        $refund = $this->completedRefund($this->paidOrder());

        app(RefundService::class)->reject($refund, 'Video tidak jelas', $this->staff('owner'));

        $this->assertSame(['refund_submitted', 'refund_rejected'], $this->queuedKeys());
    }

    public function test_the_same_event_is_never_sent_twice(): void
    {
        Mail::fake();
        $request = $this->validatedRequest($this->paidOrder());
        $notifier = app(CustomerNotifier::class);

        $notifier->cancellation($request, 'submitted');
        $notifier->cancellation($request, 'submitted');

        $this->assertSame(['cancellation_submitted'], $this->queuedKeys());
        $this->assertSame(1, DB::table('customer_notifications')->where('event_key', "cancellation:{$request->request_number}:submitted")->count());
    }

    public function test_a_send_failure_does_not_undo_the_cancellation_and_logs_no_personal_data(): void
    {
        Log::spy();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('550 rejected budi@example.com'));
        $order = $this->order();

        $cancelled = app(CancellationService::class)->cancelUnpaid($order);

        $this->assertSame(Order::CANCELLED, $cancelled->fresh()->status);
        $this->assertSame('failed', DB::table('customer_notifications')->where('order_id', $order->id)->value('status'));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'Customer notification failed'
            && ! str_contains(json_encode($context), 'budi@example.com'));
    }

    public function test_a_failed_refund_send_does_not_undo_the_refund(): void
    {
        $refund = $this->completedRefund($this->paidOrder());
        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

        app(RefundService::class)->reject($refund, 'Video tidak jelas', $this->staff('owner'));

        $this->assertSame(Refund::REJECTED, $refund->fresh()->status);
    }

    public function test_order_without_email_is_skipped_without_error(): void
    {
        Mail::fake();
        $order = $this->order();
        $order->forceFill(['customer_email' => ''])->save();

        app(CancellationService::class)->cancelUnpaid($order->fresh());

        Mail::assertNothingQueued();
        $this->assertSame('skipped', DB::table('customer_notifications')->where('order_id', $order->id)->value('status'));
        $this->assertSame(Order::CANCELLED, $order->fresh()->status);
    }

    public function test_nothing_is_sent_when_the_business_transaction_rolls_back(): void
    {
        Mail::fake();
        $order = $this->order();

        try {
            DB::transaction(function () use ($order) {
                app(CustomerNotifier::class)->orderCancelled($order);

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        Mail::assertNothingQueued();
        $this->assertSame(0, DB::table('customer_notifications')->count());
    }

    public function test_store_language_from_checkout_is_used_for_the_email(): void
    {
        Mail::fake();
        $order = $this->order('en');
        $this->assertSame('en', $order->locale);

        app(CancellationService::class)->cancelUnpaid($order);

        Mail::assertQueued(CustomerOrderUpdate::class, fn (CustomerOrderUpdate $mail) => $mail->locale === 'en');
        $this->assertStringContainsString('has been cancelled', (new CustomerOrderUpdate('cancelled_unpaid', $order->order_number))->locale('en')->render());
    }

    public function test_whatsapp_is_off_by_default_and_sends_nothing(): void
    {
        Mail::fake();
        Http::fake();
        config(['services.whatsapp.token' => 'token', 'services.whatsapp.phone_number_id' => '123']);
        Setting::where('key', 'notifications.customer_whatsapp_template')->update(['value' => 'order_update']);

        app(CancellationService::class)->cancelUnpaid($this->order());

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('customer_notifications')->where('channel', 'whatsapp')->count());
    }

    public function test_whatsapp_switch_without_a_template_sends_nothing(): void
    {
        Mail::fake();
        Http::fake();
        config(['services.whatsapp.token' => 'token', 'services.whatsapp.phone_number_id' => '123']);
        Setting::where('key', 'notifications.customer_whatsapp_enabled')->update(['value' => '1']);

        app(CancellationService::class)->cancelUnpaid($this->order());

        Http::assertNothingSent();
        $this->assertSame('skipped', DB::table('customer_notifications')->where('channel', 'whatsapp')->value('status'));
    }

    public function test_whatsapp_uses_the_template_name_from_settings_when_switched_on(): void
    {
        Mail::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        config(['services.whatsapp.token' => 'token', 'services.whatsapp.phone_number_id' => '123']);
        Setting::where('key', 'notifications.customer_whatsapp_enabled')->update(['value' => '1']);
        Setting::where('key', 'notifications.customer_whatsapp_template')->update(['value' => 'order_update']);
        $order = $this->order();

        app(CancellationService::class)->cancelUnpaid($order);

        Http::assertSentCount(1);
        Http::assertSent(fn ($sent) => $sent['template']['name'] === 'order_update' && $sent['to'] === '6281234567890'
            && $sent['template']['components'][0]['parameters'][0]['text'] === $order->order_number);
    }

    public function test_every_message_renders_in_both_languages_with_the_check_link(): void
    {
        $keys = array_keys((require base_path('lang/id/customer_mail.php'))['messages']);
        $this->assertSame($keys, array_keys((require base_path('lang/en/customer_mail.php'))['messages']));

        foreach (['id', 'en'] as $locale) {
            foreach ($keys as $key) {
                $html = (new CustomerOrderUpdate($key, 'TF-TEST-1', 'RF2611-000001'))->locale($locale)->render();

                $this->assertStringNotContainsString('customer_mail.', $html, "{$key} {$locale}");
                $this->assertStringContainsString('TF-TEST-1', $html);
                $this->assertStringContainsString("/cek-pesanan?lang={$locale}", $html);
            }
        }
    }

    public function test_email_status_becomes_sent_when_the_mail_is_actually_delivered(): void
    {
        $order = $this->order();

        app(CancellationService::class)->cancelUnpaid($order);

        $this->assertSame('sent', CustomerNotification::where('order_id', $order->id)->where('channel', 'mail')->value('status'));
    }

    public function test_a_failed_queue_job_marks_the_row_failed_and_keeps_no_error_text(): void
    {
        Mail::fake();
        $order = $this->order();
        app(CancellationService::class)->cancelUnpaid($order);
        $row = CustomerNotification::where('order_id', $order->id)->firstOrFail();

        (new CustomerOrderUpdate($row->message, $order->order_number, null, $row->id))->failed(new RuntimeException('550 budi@example.com rejected'));

        $row->refresh();
        $this->assertSame('failed', $row->status);
        $this->assertSame('RuntimeException', $row->error);
    }

    public function test_late_payment_refund_sends_one_email(): void
    {
        Mail::fake();
        $order = $this->order();
        $order->forceFill(['status' => Order::EXPIRED, 'payment_status' => 'expired'])->save();
        PaymentEvent::create(['order_id' => $order->id, 'transaction_id' => 'trx-'.$order->id, 'transaction_status' => 'settlement', 'gross_amount' => (int) $order->total, 'payload' => []]);

        app(CancellationService::class)->markLatePaymentRefunded($order->fresh(), 'Transfer ref 123', $this->staff('store-admin'));

        $this->assertSame(['late_payment_refunded'], $this->queuedKeys());
    }

    public function test_incomplete_evidence_asks_the_customer_to_resend_without_the_staff_note_and_can_repeat(): void
    {
        Mail::fake();
        $refund = $this->completedRefund($this->paidOrder());
        $service = app(RefundService::class);
        $cs = $this->staff('customer-service');

        $service->recordEvidence($refund, 'insufficient', 'whatsapp', 'Resi tidak terlihat, catatan internal', $cs);
        $service->recordEvidence($refund, 'insufficient', 'whatsapp', 'Masih kurang jelas', $cs);

        $this->assertSame(['refund_submitted', 'refund_evidence_insufficient', 'refund_evidence_insufficient'], $this->queuedKeys());
        $this->assertStringNotContainsString('catatan internal', Mail::queued(CustomerOrderUpdate::class)->last()->render());
    }

    public function test_whatsapp_job_carries_only_an_id_never_the_token_or_the_number(): void
    {
        Mail::fake();
        Queue::fake();
        config(['services.whatsapp.token' => 'secret-token-xyz', 'services.whatsapp.phone_number_id' => '123']);
        Setting::where('key', 'notifications.customer_whatsapp_enabled')->update(['value' => '1']);
        Setting::where('key', 'notifications.customer_whatsapp_template')->update(['value' => 'order_update']);

        app(CancellationService::class)->cancelUnpaid($this->order());

        Queue::assertPushed(SendCustomerWhatsApp::class, function (SendCustomerWhatsApp $job) {
            $payload = serialize($job);

            return ! str_contains($payload, 'secret-token-xyz') && ! str_contains($payload, '6281234567890') && ! str_contains($payload, '081234567890');
        });
    }

    public function test_a_whatsapp_job_failure_marks_the_row_failed(): void
    {
        $order = $this->order();
        $row = CustomerNotification::create(['order_id' => $order->id, 'event_key' => 'order:X:cancelled', 'channel' => 'whatsapp', 'message' => 'cancelled_unpaid', 'status' => 'queued']);

        (new SendCustomerWhatsApp($row->id))->failed(new RuntimeException('secret'));

        $this->assertSame('failed', $row->fresh()->status);
    }

    public function test_cs_lists_and_resends_a_failed_email_and_it_is_audited(): void
    {
        Mail::fake();
        $refund = $this->completedRefund($this->paidOrder());
        $row = CustomerNotification::where('event_key', "refund:{$refund->refund_number}:submitted")->firstOrFail();
        $row->forceFill(['status' => 'failed', 'error' => 'Exception'])->save();
        $cs = $this->staff('customer-service');

        $list = $this->actingAs($cs)->getJson("/api/v1/admin/refunds/{$refund->public_ref}/customer-emails")->assertOk()->json('data.notifications');
        $this->assertSame($row->public_id, $list[0]['id']);
        $this->assertTrue($list[0]['can_resend']);
        $this->assertStringNotContainsString('@', json_encode($list));

        $this->actingAs($cs)->postJson("/api/v1/admin/refunds/{$refund->public_ref}/customer-notifications/{$row->public_id}/resend")->assertOk();

        Mail::assertQueuedCount(2);
        $this->assertSame('queued', $row->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer_notification.resent', 'actor_user_id' => $cs->id]);
    }

    public function test_resend_is_refused_for_a_sent_row_of_another_target_and_for_staff_without_the_permission(): void
    {
        Mail::fake();
        $refund = $this->completedRefund($this->paidOrder());
        $other = $this->completedRefund($this->paidOrder(), 'refund-key-2');
        $row = CustomerNotification::where('event_key', "refund:{$refund->refund_number}:submitted")->firstOrFail();
        $row->forceFill(['status' => 'failed'])->save();

        $this->actingAs($this->staff('customer-service'))->postJson("/api/v1/admin/refunds/{$other->public_ref}/customer-notifications/{$row->public_id}/resend")->assertNotFound();
        $this->actingAs($this->staff('warehouse'))->postJson("/api/v1/admin/refunds/{$refund->public_ref}/customer-notifications/{$row->public_id}/resend")->assertForbidden();

        $row->forceFill(['status' => 'queued'])->save();
        $this->actingAs($this->staff('customer-service'))->postJson("/api/v1/admin/refunds/{$refund->public_ref}/customer-notifications/{$row->public_id}/resend")->assertStatus(422);
        // Hanya dua email pengajuan awal; tidak satu pun kiriman ulang yang lolos.
        Mail::assertQueuedCount(2);
    }

    public function test_only_the_super_admin_changes_the_whatsapp_switch_and_the_template_is_validated(): void
    {
        $this->actingAs($this->staff('store-admin'))->patchJson('/api/v1/admin/settings/notifications.customer_whatsapp_enabled', ['value' => 1])->assertForbidden();

        $super = $this->staff('super-admin');
        $this->actingAs($super)->patchJson('/api/v1/admin/settings/notifications.customer_whatsapp_template', ['value' => 'Bad Name!'])->assertStatus(422);
        $this->actingAs($super)->patchJson('/api/v1/admin/settings/notifications.customer_whatsapp_template', ['value' => 'order_update'])->assertOk();
        $this->actingAs($super)->patchJson('/api/v1/admin/settings/notifications.customer_whatsapp_enabled', ['value' => 2])->assertStatus(422);
    }
}
