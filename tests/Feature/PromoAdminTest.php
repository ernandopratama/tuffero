<?php

namespace Tests\Feature;

use App\Models\AdminComment;
use App\Models\AuditLog;
use App\Models\PromoContent;
use App\Models\User;
use App\Models\Voucher;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PromoAdminTest extends TestCase
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

    private function voucherData(array $overrides = []): array
    {
        return ['code' => 'hemat10', 'type' => 'percent', 'value' => 10, 'min_subtotal' => 0, 'max_discount' => 20000, 'quota' => 100, 'is_active' => false, ...$overrides];
    }

    private function voucher(array $overrides = []): Voucher
    {
        return Voucher::create([...$this->voucherData(), 'code' => 'HEMAT10', ...$overrides]);
    }

    private function content(array $overrides = []): PromoContent
    {
        return PromoContent::create(['type' => 'text', 'title' => 'Promo', 'body' => 'Isi', ...$overrides]);
    }

    /** @return array<string, array{string, string, string, int}> */
    public static function accessProvider(): array
    {
        return [
            'marketing lihat voucher' => ['marketing', 'getJson', '/api/v1/admin/vouchers', 200],
            'admin toko lihat voucher' => ['store-admin', 'getJson', '/api/v1/admin/vouchers', 200],
            'pemilik lihat voucher' => ['owner', 'getJson', '/api/v1/admin/vouchers', 200],
            'cs tidak lihat voucher' => ['customer-service', 'getJson', '/api/v1/admin/vouchers', 403],
            'gudang tidak lihat voucher' => ['warehouse', 'getJson', '/api/v1/admin/vouchers', 403],
            'pemilik tidak buat voucher' => ['owner', 'postJson', '/api/v1/admin/vouchers', 403],
            'marketing lihat konten' => ['marketing', 'getJson', '/api/v1/admin/contents', 200],
            'pemilik lihat konten' => ['owner', 'getJson', '/api/v1/admin/contents', 200],
            'admin toko tidak lihat konten' => ['store-admin', 'getJson', '/api/v1/admin/contents', 403],
            'cs tidak lihat konten' => ['customer-service', 'getJson', '/api/v1/admin/contents', 403],
            'pemilik tidak buat konten' => ['owner', 'postJson', '/api/v1/admin/contents', 403],
            'admin toko tidak buat konten' => ['store-admin', 'postJson', '/api/v1/admin/contents', 403],
        ];
    }

    #[DataProvider('accessProvider')]
    public function test_role_access(string $role, string $method, string $url, int $status): void
    {
        $this->actingAs($this->staff($role))->{$method}($url, [])->assertStatus($status);
    }

    public function test_writes_require_login_and_enrolled_mfa(): void
    {
        $this->postJson('/api/v1/admin/vouchers', [])->assertUnauthorized();

        $this->actingAs($this->staff('marketing', ['mfa_totp_confirmed_at' => null]))
            ->postJson('/api/v1/admin/vouchers', $this->voucherData())
            ->assertForbidden();
    }

    public function test_voucher_is_created_inactive_uppercase_and_audited(): void
    {
        $marketing = $this->staff('marketing');

        $id = $this->actingAs($marketing)->postJson('/api/v1/admin/vouchers', $this->voucherData())
            ->assertCreated()
            ->assertJsonPath('data.code', 'HEMAT10')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.used_count', 0)
            ->json('data.id');

        $this->patchJson("/api/v1/admin/vouchers/{$id}", $this->voucherData(['value' => 15]))->assertOk();

        $this->assertSame($marketing->id, AuditLog::where('action', 'voucher.created')->value('actor_user_id'));
        $this->assertSame(['old' => 10, 'new' => 15], AuditLog::where('action', 'voucher.updated')->firstOrFail()->changes['value']);
    }

    public function test_used_count_cannot_be_changed_from_request(): void
    {
        $voucher = $this->voucher();

        $this->actingAs($this->staff('marketing'))
            ->patchJson("/api/v1/admin/vouchers/{$voucher->id}", $this->voucherData(['used_count' => 50]))
            ->assertOk();

        $this->assertSame(0, $voucher->fresh()->used_count);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidVoucherProvider(): array
    {
        return [
            'kode pendek' => [['code' => 'AB'], 'code'],
            'kode simbol' => [['code' => 'A B<C'], 'code'],
            'persen di atas 100' => [['value' => 101], 'value'],
            'nilai nol' => [['value' => 0], 'value'],
            'nilai minus' => [['value' => -5], 'value'],
            'jenis asing' => [['type' => 'gratis'], 'type'],
            'batas diskon pada potongan tetap' => [['type' => 'fixed', 'value' => 5000, 'max_discount' => 100], 'max_discount'],
            'tanggal terbalik' => [['starts_at' => '2027-02-02', 'ends_at' => '2027-01-01'], 'ends_at'],
            'minimum minus' => [['min_subtotal' => -1], 'min_subtotal'],
        ];
    }

    #[DataProvider('invalidVoucherProvider')]
    public function test_voucher_validation(array $overrides, string $field): void
    {
        $this->actingAs($this->staff('marketing'))
            ->postJson('/api/v1/admin/vouchers', $this->voucherData($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    public function test_voucher_code_must_be_unique_and_quota_not_below_used(): void
    {
        $voucher = $this->voucher();
        $voucher->forceFill(['used_count' => 5])->save();
        $this->actingAs($this->staff('marketing'));

        $this->postJson('/api/v1/admin/vouchers', $this->voucherData())->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->patchJson("/api/v1/admin/vouchers/{$voucher->id}", $this->voucherData(['quota' => 4]))->assertUnprocessable()->assertJsonValidationErrors('quota');
        $this->patchJson("/api/v1/admin/vouchers/{$voucher->id}", $this->voucherData(['quota' => 5]))->assertOk();
    }

    /** @return array<string, array{string, bool}> */
    public static function videoUrlProvider(): array
    {
        return [
            'youtube' => ['https://www.youtube.com/watch?v=abc', true],
            'youtu.be' => ['https://youtu.be/abc', true],
            'tiktok' => ['https://www.tiktok.com/@tuffero/video/1', true],
            'instagram' => ['https://www.instagram.com/reel/abc/', true],
            'http' => ['http://youtube.com/watch?v=abc', false],
            'domain palsu' => ['https://youtube.com.evil.test/watch', false],
            'domain mirip' => ['https://notyoutube.com/watch', false],
            'userinfo' => ['https://youtube.com@evil.test/watch', false],
            'userinfo host benar' => ['https://evil:x@youtube.com/watch', false],
            'backslash' => ['https://evil.test\\@youtube.com/watch', false],
            'port' => ['https://youtube.com:8443/watch', false],
            'tanpa path' => ['https://youtube.com', false],
            'javascript' => ['javascript:alert(1)', false],
            'kode embed' => ['<iframe src="https://youtube.com/embed/a"></iframe>', false],
            'lain' => ['https://vimeo.com/123', false],
        ];
    }

    #[DataProvider('videoUrlProvider')]
    public function test_video_url_allowlist(string $url, bool $allowed): void
    {
        $response = $this->actingAs($this->staff('marketing'))
            ->postJson('/api/v1/admin/contents', ['type' => 'video', 'title' => 'Video', 'video_url' => $url]);

        $allowed ? $response->assertCreated() : $response->assertUnprocessable()->assertJsonValidationErrors('video_url');
    }

    public function test_content_is_draft_on_create_and_type_cannot_change(): void
    {
        $this->actingAs($this->staff('marketing'));

        $id = $this->postJson('/api/v1/admin/contents', ['type' => 'text', 'title' => 'Promo', 'body' => 'Isi', 'is_published' => true])
            ->assertCreated()
            ->assertJsonPath('data.is_published', false)
            ->json('data.id');

        $this->patchJson("/api/v1/admin/contents/{$id}", ['type' => 'banner', 'title' => 'Promo', 'body' => 'Isi'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_content_fields_follow_type(): void
    {
        $this->actingAs($this->staff('marketing'));

        $this->postJson('/api/v1/admin/contents', ['type' => 'text', 'title' => 'Promo'])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson('/api/v1/admin/contents', ['type' => 'video', 'title' => 'Video'])->assertUnprocessable()->assertJsonValidationErrors('video_url');
        $this->postJson('/api/v1/admin/contents', ['type' => 'text', 'title' => 'x', 'body' => 'y', 'video_url' => 'https://youtu.be/a'])->assertUnprocessable()->assertJsonValidationErrors('video_url');
    }

    public function test_banner_needs_image_before_publish_and_upload_is_validated(): void
    {
        Storage::fake('public');
        $this->actingAs($this->staff('marketing'));
        $content = $this->content(['type' => 'banner', 'body' => null]);
        $url = "/api/v1/admin/contents/{$content->id}";

        $this->patchJson($url, ['title' => 'Promo', 'is_published' => true])->assertUnprocessable()->assertJsonValidationErrors('is_published');

        $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->postJson("{$url}/image", ['image' => $svg])->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->postJson("{$url}/image", ['image' => UploadedFile::fake()->image('b.jpg')->size(3000)])->assertUnprocessable();

        $first = $this->postJson("{$url}/image", ['image' => UploadedFile::fake()->image('a.jpg')])->assertOk()->json('data.image_url');
        $this->postJson("{$url}/image", ['image' => UploadedFile::fake()->image('c.jpg')])->assertOk();

        Storage::disk('public')->assertMissing(str_replace('/storage/', '', $first));
        $this->patchJson($url, ['title' => 'Promo', 'is_published' => true])->assertOk()->assertJsonPath('data.is_published', true);
    }

    public function test_comment_is_stored_with_author_and_cannot_be_edited(): void
    {
        $owner = $this->staff('owner');
        $voucher = $this->voucher();
        $url = "/api/v1/admin/vouchers/{$voucher->id}/comments";

        $this->actingAs($owner)->postJson($url, ['body' => '<b>Saran</b> kuota dinaikkan'])
            ->assertCreated()
            ->assertJsonPath('data.author', $owner->name)
            ->assertJsonPath('data.body', '<b>Saran</b> kuota dinaikkan');

        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.comments');

        $this->assertSame('<b>Saran</b> kuota dinaikkan', AdminComment::firstOrFail()->body);
        $this->assertSame(['GET', 'HEAD', 'POST'], $this->commentRouteMethods());
    }

    /** @return list<string> */
    private function commentRouteMethods(): array
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'comments'))
            ->flatMap(fn ($route) => $route->methods())
            ->unique()
            ->values()
            ->all();
    }

    public function test_comment_requires_access_to_target(): void
    {
        $voucher = $this->voucher();
        $content = $this->content();

        $this->actingAs($this->staff('customer-service'))
            ->postJson("/api/v1/admin/vouchers/{$voucher->id}/comments", ['body' => 'x'])
            ->assertForbidden();

        $this->actingAs($this->staff('store-admin'))
            ->getJson("/api/v1/admin/contents/{$content->id}/comments")
            ->assertForbidden();

        $this->actingAs($this->staff('owner'))
            ->postJson("/api/v1/admin/contents/{$content->id}/comments", ['body' => ''])
            ->assertUnprocessable();
    }
}
