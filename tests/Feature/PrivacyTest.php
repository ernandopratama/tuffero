<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\TermsSection;
use App\Models\User;
use App\Services\TermsService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PrivacyDraftSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Kebijakan Privasi lewat CRUD (D-139): tabel yang sama dengan S&K, dibedakan kolom `type`. Dasar S&K diuji di TermsTest. */
class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1/admin/privacy';

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

    /** @param array<string, mixed> $override */
    private function payload(array $override = []): array
    {
        return [
            'title_id' => 'Data yang dikumpulkan', 'title_en' => 'Data collected',
            'body_id' => "Paragraf satu.\n\nParagraf dua.", 'body_en' => "Paragraph one.\n\nParagraph two.",
            'is_published' => true,
            ...$override,
        ];
    }

    private function privacy(): Builder
    {
        return TermsSection::where('type', TermsSection::PRIVACY);
    }

    private function make(string $type, int $order, string $title, bool $published = true): TermsSection
    {
        return TermsSection::create(['type' => $type, 'sort_order' => $order, 'title_id' => $title, 'title_en' => $title.' EN', 'body_id' => 'Isi '.$title, 'body_en' => 'Body '.$title, 'is_published' => $published]);
    }

    public function test_the_existing_terms_rows_become_the_terms_type_and_privacy_starts_empty(): void
    {
        $this->assertSame(10, TermsSection::where('type', TermsSection::TERMS)->count());
        $this->assertSame(0, $this->privacy()->count());
        $this->assertSame('draf', app(TermsService::class)->version(TermsSection::PRIVACY));
    }

    public function test_only_store_admin_and_super_admin_manage_the_privacy_policy(): void
    {
        $this->getJson(self::API)->assertUnauthorized();
        $this->postJson(self::API, $this->payload())->assertUnauthorized();

        foreach (['owner', 'customer-service', 'warehouse', 'marketing'] as $role) {
            $user = $this->staff($role);
            $this->actingAs($user)->getJson(self::API)->assertForbidden();
            $this->actingAs($user)->postJson(self::API, $this->payload())->assertForbidden();
        }

        foreach (['store-admin', 'super-admin'] as $role) {
            $this->actingAs($this->staff($role))->getJson(self::API)->assertOk()->assertJsonCount(0, 'data.sections');
        }

        $this->actingAs($this->staff('store-admin', ['mfa_totp_confirmed_at' => null]))->postJson(self::API, $this->payload())->assertForbidden();
        $this->assertSame(0, $this->privacy()->count());
    }

    public function test_create_update_and_delete_work_with_their_own_version_and_audit_trail(): void
    {
        $admin = $this->staff('store-admin');
        $termsVersion = app(TermsService::class)->version();

        $id = $this->actingAs($admin)->postJson(self::API, $this->payload())->assertCreated()->json('data.id');
        $this->assertSame(TermsSection::PRIVACY, TermsSection::where('public_id', $id)->first()->type);
        $first = app(TermsService::class)->version(TermsSection::PRIVACY);
        $this->assertSame(now()->format('Y-m-d').'.1', $first);

        $this->actingAs($admin)->putJson(self::API."/{$id}", $this->payload(['title_id' => 'Judul baru', 'is_published' => false]))->assertOk();
        $this->assertSame(now()->format('Y-m-d').'.2', app(TermsService::class)->version(TermsSection::PRIVACY));
        $this->assertSame(['Judul baru', false], [TermsSection::where('public_id', $id)->first()->title_id, TermsSection::where('public_id', $id)->first()->is_published]);

        $this->actingAs($admin)->deleteJson(self::API."/{$id}")->assertOk();
        $this->assertNull(TermsSection::where('public_id', $id)->first());
        $this->assertSame(now()->format('Y-m-d').'.3', app(TermsService::class)->version(TermsSection::PRIVACY));

        // Versi S&K (yang dicatat di pengajuan refund) tidak ikut berubah.
        $this->assertSame($termsVersion, app(TermsService::class)->version());
        $this->assertSame(['privacy.created', 'privacy.updated', 'privacy.deleted'], AuditLog::where('action', 'like', 'privacy.%')->orderBy('id')->pluck('action')->all());
        $this->assertSame(0, AuditLog::where('action', 'like', 'terms.%')->count());
    }

    public function test_a_terms_change_does_not_touch_the_privacy_version(): void
    {
        $admin = $this->staff('store-admin');
        $this->actingAs($admin)->postJson(self::API, $this->payload())->assertCreated();
        $privacy = app(TermsService::class)->version(TermsSection::PRIVACY);
        $terms = app(TermsService::class)->version();

        $this->actingAs($admin)->postJson('/api/v1/admin/terms', $this->payload())->assertCreated();

        $this->assertNotSame($terms, app(TermsService::class)->version());
        $this->assertSame($privacy, app(TermsService::class)->version(TermsSection::PRIVACY));
    }

    public function test_validation_rejects_empty_oversized_and_html_only_input(): void
    {
        $admin = $this->staff('store-admin');

        foreach (['title_id', 'title_en', 'body_id', 'body_en'] as $field) {
            $this->actingAs($admin)->postJson(self::API, $this->payload([$field => '']))->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->actingAs($admin)->postJson(self::API, $this->payload([$field => '<b></b>']))->assertUnprocessable();
        }
        $this->actingAs($admin)->postJson(self::API, $this->payload(['title_id' => str_repeat('a', 151)]))->assertUnprocessable()->assertJsonValidationErrors('title_id');
        $this->actingAs($admin)->postJson(self::API, $this->payload(['body_en' => str_repeat('a', 4001)]))->assertUnprocessable()->assertJsonValidationErrors('body_en');

        $this->assertSame(0, $this->privacy()->count());
        $this->assertSame('draf', app(TermsService::class)->version(TermsSection::PRIVACY), 'penolakan tidak menaikkan versi');
    }

    public function test_html_is_stripped_on_save_and_escaped_on_the_store(): void
    {
        $admin = $this->staff('store-admin');
        $id = $this->actingAs($admin)->postJson(self::API, $this->payload(['title_id' => 'Judul <i>miring</i>', 'body_id' => 'Isi <script>alert(1)</script>aman']))->assertCreated()->json('data.id');

        $this->assertSame('Judul miring', TermsSection::where('public_id', $id)->first()->title_id);
        $this->assertSame('Isi alert(1)aman', TermsSection::where('public_id', $id)->first()->body_id);

        TermsSection::where('public_id', $id)->first()->update(['body_id' => '<script>window.hacked=1</script><b>tebal</b>']);
        $html = $this->get('/kontak')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>window.hacked', $html);
        $this->assertStringContainsString('&lt;script&gt;window.hacked=1&lt;/script&gt;', $html);
    }

    public function test_a_section_of_one_type_is_not_reachable_through_the_other_types_routes(): void
    {
        $admin = $this->staff('store-admin');
        $privacyId = $this->actingAs($admin)->postJson(self::API, $this->payload())->json('data.id');
        $termsId = TermsSection::where('type', TermsSection::TERMS)->value('public_id');

        $this->actingAs($admin)->putJson("/api/v1/admin/terms/{$privacyId}", $this->payload())->assertNotFound();
        $this->actingAs($admin)->deleteJson("/api/v1/admin/terms/{$privacyId}")->assertNotFound();
        $this->actingAs($admin)->postJson("/api/v1/admin/terms/{$privacyId}/move", ['direction' => 'up'])->assertNotFound();
        $this->actingAs($admin)->putJson(self::API."/{$termsId}", $this->payload())->assertNotFound();
        $this->actingAs($admin)->deleteJson(self::API."/{$termsId}")->assertNotFound();
        $this->actingAs($admin)->postJson(self::API."/{$termsId}/move", ['direction' => 'down'])->assertNotFound();

        $this->assertNotNull(TermsSection::where('public_id', $privacyId)->first());
        $this->assertNotNull(TermsSection::where('public_id', $termsId)->first());
        $this->actingAs($admin)->getJson('/api/v1/admin/terms')->assertJsonCount(10, 'data.sections');
    }

    public function test_move_works_per_type_and_never_swaps_across_types(): void
    {
        $admin = $this->staff('store-admin');
        $termsBefore = TermsSection::where('type', TermsSection::TERMS)->orderBy('id')->pluck('sort_order', 'id')->all();
        $a = $this->make(TermsSection::PRIVACY, 5, 'Pertama');
        $b = $this->make(TermsSection::PRIVACY, 6, 'Kedua');
        $order = fn () => $this->privacy()->orderBy('sort_order')->orderBy('id')->pluck('title_id')->all();

        $this->actingAs($admin)->postJson(self::API."/{$b->public_id}/move", ['direction' => 'up'])->assertOk();
        $this->assertSame(['Kedua', 'Pertama'], $order());

        // Ujung daftar Privasi: tidak bisa naik lagi, walau di atasnya ada butir S&K dengan nomor urut lebih kecil.
        $this->actingAs($admin)->postJson(self::API.'/'.$b->public_id.'/move', ['direction' => 'up'])->assertOk();
        $this->assertSame(['Kedua', 'Pertama'], $order());
        $this->assertSame($termsBefore, TermsSection::where('type', TermsSection::TERMS)->orderBy('id')->pluck('sort_order', 'id')->all(), 'urutan S&K tidak berubah');

        $this->actingAs($admin)->postJson(self::API."/{$a->public_id}/move", ['direction' => 'samping'])->assertUnprocessable();
    }

    public function test_the_store_shows_published_privacy_items_in_order_with_their_own_version_in_both_languages(): void
    {
        $this->make(TermsSection::PRIVACY, 20, 'Kedua');
        $this->make(TermsSection::PRIVACY, 10, 'Pertama');
        $this->make(TermsSection::PRIVACY, 5, 'Tersembunyi', false);
        $this->actingAs($this->staff('store-admin'))->postJson(self::API, $this->payload(['title_id' => 'Ketiga', 'title_en' => 'Third', 'is_published' => true]))->assertCreated();
        $version = app(TermsService::class)->version(TermsSection::PRIVACY);

        $id = $this->get('/kontak')->assertOk();
        $id->assertSeeInOrder(['Kebijakan Privasi', 'Pertama', 'Isi Pertama', 'Kedua', 'Ketiga']);
        $id->assertDontSee('Tersembunyi');
        $id->assertDontSee('Data yang kami kumpulkan', false);
        $id->assertSee('Versi '.$version, false);
        // S&K tetap tampil dari tabelnya sendiri dengan versinya sendiri.
        $id->assertSee('Ketentuan umum')->assertSee('Versi '.app(TermsService::class)->version(), false);

        $this->get('/kontak?lang=en')->assertOk()->assertSeeInOrder(['Pertama EN', 'Kedua EN', 'Third'])->assertSee('Version '.$version, false)->assertDontSee('Data we collect', false);
    }

    public function test_the_store_falls_back_to_the_built_in_privacy_text_when_nothing_is_published(): void
    {
        $this->get('/kontak')->assertOk()->assertSee('Data yang kami kumpulkan')->assertSee('id="privasi"', false);
        $this->get('/kontak?lang=en')->assertOk()->assertSee('Data we collect');

        $this->make(TermsSection::PRIVACY, 10, 'Hanya draf', false);
        $this->get('/kontak')->assertOk()->assertSee('Data yang kami kumpulkan')->assertDontSee('Hanya draf');

        // Butir S&K tidak pernah dianggap butir Privasi.
        $this->assertSame(0, TermsSection::published(TermsSection::PRIVACY)->count());
    }

    public function test_the_optional_seeder_copies_the_built_in_text_as_unpublished_drafts_once(): void
    {
        $this->seed(PrivacyDraftSeeder::class);

        $this->assertSame(6, $this->privacy()->count());
        $this->assertSame(0, $this->privacy()->where('is_published', true)->count());
        $first = $this->privacy()->orderBy('sort_order')->first();
        $this->assertSame('Data yang kami kumpulkan', $first->title_id);
        $this->assertSame('Data we collect', $first->title_en);
        $this->assertSame('draf', app(TermsService::class)->version(TermsSection::PRIVACY), 'menyalin tidak menaikkan versi');
        $this->get('/kontak')->assertOk()->assertSee('Data yang kami kumpulkan');

        $this->seed(PrivacyDraftSeeder::class);
        $this->assertSame(6, $this->privacy()->count(), 'dijalankan ulang tidak menggandakan');
    }

    public function test_the_default_seeding_does_not_create_privacy_items(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, $this->privacy()->count());
    }
}
