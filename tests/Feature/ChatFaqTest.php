<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ChatFaq;
use App\Models\User;
use Database\Seeders\ChatFaqSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * FAQ chat (D-060): admin mengelola, toko hanya membaca yang aktif.
 */
class ChatFaqTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function staff(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now(), ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, string> */
    private function payload(array $overrides = []): array
    {
        return ['title' => 'Pembayaran', 'keywords' => 'Bayar, transfer ,  QRIS', 'answer_id' => 'Jawaban uji.', ...$overrides];
    }

    /** @return array<string, array{string, int}> */
    public static function roleAccess(): array
    {
        return [
            'super admin boleh' => ['super-admin', 201],
            'admin toko boleh' => ['store-admin', 201],
            'cs boleh' => ['customer-service', 201],
            'pemilik tidak' => ['owner', 403],
            'gudang tidak' => ['warehouse', 403],
            'marketing tidak' => ['marketing', 403],
        ];
    }

    public function test_seeder_adds_the_starter_faqs_once_and_never_overwrites_admin_edits(): void
    {
        $this->assertSame(
            ['Jam operasional', 'Pengiriman', 'Cara memesan', 'Daya tahan produk', 'Hampers dan paket hadiah', 'Kode voucher', 'Alergi, halal, dan izin edar', 'Kontak dan media sosial', 'Pesanan dalam jumlah besar', 'Keluhan atau barang bermasalah'],
            ChatFaq::orderBy('sort_order')->pluck('title')->all(),
        );
        $this->assertSame(5, ChatFaq::where('is_suggested', true)->count(), 'Chat menampilkan paling banyak 5 tombol saran.');

        ChatFaq::where('title', 'Pengiriman')->update(['answer_id' => 'Diubah admin.', 'is_active' => false]);
        $this->seed(ChatFaqSeeder::class);

        $this->assertSame(10, ChatFaq::count());
        $this->assertSame('Diubah admin.', ChatFaq::where('title', 'Pengiriman')->value('answer_id'));
    }

    public function test_starter_faqs_never_promise_payment_or_refund_policy(): void
    {
        foreach (ChatFaq::all() as $faq) {
            $this->assertDoesNotMatchRegularExpression('/refund|pengembalian dana|midtrans|transfer|gopay|ovo|qris/i', $faq->keywords.' '.$faq->answer_id.' '.$faq->answer_en);
        }
    }

    #[DataProvider('roleAccess')]
    public function test_only_faq_managers_create_faqs(string $role, int $status): void
    {
        $this->actingAs($this->staff($role))->postJson('/api/v1/admin/faqs', $this->payload())->assertStatus($status);
        $this->actingAs($this->staff($role))->getJson('/api/v1/admin/faqs')->assertStatus($status === 201 ? 200 : 403);
    }

    public function test_guests_cannot_use_admin_faq_api_and_writes_need_mfa(): void
    {
        $this->getJson('/api/v1/admin/faqs')->assertUnauthorized();
        $this->postJson('/api/v1/admin/faqs', $this->payload())->assertUnauthorized();

        $this->actingAs($this->staff('customer-service', ['mfa_totp_confirmed_at' => null]))
            ->postJson('/api/v1/admin/faqs', $this->payload())->assertForbidden();
    }

    public function test_create_and_update_normalize_keywords_and_write_audit(): void
    {
        $admin = $this->staff('customer-service');
        $this->actingAs($admin);

        $id = $this->postJson('/api/v1/admin/faqs', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.keywords', 'bayar, transfer, qris')
            ->assertJsonPath('data.sort_order', 11)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_suggested', false)
            ->json('data.id');

        $this->patchJson("/api/v1/admin/faqs/{$id}", ['answer_id' => 'Jawaban baru.', 'answer_en' => '  ', 'title_en' => ' ', 'is_active' => false, 'is_suggested' => true])
            ->assertOk()
            ->assertJsonPath('data.answer_id', 'Jawaban baru.')
            ->assertJsonPath('data.answer_en', null)
            ->assertJsonPath('data.title_en', null)
            ->assertJsonPath('data.is_suggested', true)
            ->assertJsonPath('data.is_active', false);

        $this->assertSame(['faq.created', 'faq.updated'], AuditLog::orderBy('id')->pluck('action')->all());
        $this->assertSame($admin->id, AuditLog::where('action', 'faq.updated')->value('actor_user_id'));
    }

    public function test_validation_rejects_short_empty_and_too_many_keywords(): void
    {
        $this->actingAs($this->staff('store-admin'));

        $this->postJson('/api/v1/admin/faqs', $this->payload(['keywords' => 'bayar, di']))->assertJsonValidationErrors('keywords');
        $this->postJson('/api/v1/admin/faqs', $this->payload(['keywords' => ' , , ']))->assertJsonValidationErrors('keywords');
        $this->postJson('/api/v1/admin/faqs', $this->payload(['keywords' => implode(',', array_map(fn ($i) => "kata{$i}", range(1, 21)))]))->assertJsonValidationErrors('keywords');
        $this->postJson('/api/v1/admin/faqs', $this->payload(['answer_id' => str_repeat('a', 601)]))->assertJsonValidationErrors('answer_id');
        $this->postJson('/api/v1/admin/faqs', $this->payload(['title_en' => str_repeat('a', 121)]))->assertJsonValidationErrors('title_en');
        $this->postJson('/api/v1/admin/faqs', ['title' => 'Tanpa isi'])->assertJsonValidationErrors(['keywords', 'answer_id']);

        $this->assertSame(10, ChatFaq::count());
    }

    public function test_public_endpoint_returns_only_active_faqs_without_login(): void
    {
        ChatFaq::create(['title' => 'Draf', 'keywords' => 'rahasia', 'answer_id' => 'Jangan tampil.', 'sort_order' => 9, 'is_active' => false]);

        $response = $this->getJson('/api/v1/chat/faqs')->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(10, $items);
        $this->assertSame(['id', 'title', 'suggested', 'keywords', 'answer'], array_keys($items[0]));
        $this->assertContains('jam buka', $items[0]['keywords']);
        $this->assertTrue($items[0]['suggested']);
        $this->assertFalse($items[5]['suggested']);
        $this->assertStringNotContainsString('Jangan tampil', json_encode($response->json()));
        $this->assertStringNotContainsString('Draf', json_encode($response->json()));
    }

    public function test_public_endpoint_uses_english_answer_and_falls_back_to_indonesian(): void
    {
        ChatFaq::where('title', 'Cara memesan')->update(['answer_en' => null, 'title_en' => null]);

        $indonesian = collect($this->getJson('/api/v1/chat/faqs')->json('data.items'));
        $english = collect($this->getJson('/api/v1/chat/faqs?lang=en')->json('data.items'));

        $this->assertStringContainsString('Jam operasional', $indonesian[0]['answer']);
        $this->assertStringContainsString('opening hours', $english[0]['answer']);
        $this->assertStringContainsString('Pilih produk', $english[2]['answer']);
        $this->assertStringContainsString('Pilih produk', $indonesian[2]['answer']);

        // Judul tombol saran ikut bahasa, dengan cadangan judul Indonesia.
        $this->assertSame('Opening hours', $english[0]['title']);
        $this->assertSame('Jam operasional', $indonesian[0]['title']);
        $this->assertSame('Cara memesan', $english[2]['title']);

        $this->getJson('/api/v1/chat/faqs?lang=zz')->assertOk()->assertJsonPath('data.items.0.answer', $indonesian[0]['answer']);
    }

    public function test_public_endpoint_is_rate_limited(): void
    {
        foreach (range(1, 60) as $attempt) {
            $this->getJson('/api/v1/chat/faqs')->assertOk();
        }

        $this->getJson('/api/v1/chat/faqs')->assertTooManyRequests();
    }
}
