<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\TermsSection;
use App\Models\User;
use App\Services\TermsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TermsTest extends TestCase
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

    /** @param array<string, mixed> $override */
    private function payload(array $override = []): array
    {
        return [
            'title_id' => 'Garansi rasa', 'title_en' => 'Taste guarantee',
            'body_id' => "Paragraf satu.\n\nParagraf dua.", 'body_en' => "Paragraph one.\n\nParagraph two.",
            'is_published' => true,
            ...$override,
        ];
    }

    private function version(): string
    {
        return app(TermsService::class)->version();
    }

    public function test_the_default_terms_carry_the_decided_return_and_refund_rules_in_both_languages(): void
    {
        $sections = TermsSection::orderBy('sort_order')->get();

        $this->assertCount(10, $sections);
        $this->assertTrue($sections->every(fn ($s) => $s->is_published && $s->title_id !== '' && $s->title_en !== '' && $s->body_en !== ''));

        $returns = $sections->firstWhere('title_id', 'Retur dan klaim: batas waktu');
        $this->assertStringContainsString('3 hari kalender', $returns->body_id);
        $this->assertStringContainsString('mengonfirmasi ke CS', $returns->body_id);
        $this->assertStringContainsString('7 hari', $returns->body_id);
        $this->assertStringContainsString('3 calendar days', $returns->body_en);

        $video = $sections->firstWhere('title_id', 'Video unboxing wajib');
        $this->assertStringContainsString('nomor resi', $video->body_id);
        $this->assertStringContainsString('masih tersegel', $video->body_id);

        $this->assertStringContainsString('tidak dicoba dan tidak dibuka', $sections->firstWhere('title_id', 'Kondisi produk yang diajukan')->body_id);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}\.\d+$/', $this->version());
    }

    public function test_only_store_admin_and_super_admin_manage_the_terms(): void
    {
        $this->getJson('/api/v1/admin/terms')->assertUnauthorized();

        foreach (['owner', 'customer-service', 'warehouse', 'marketing'] as $role) {
            $user = $this->staff($role);
            $this->actingAs($user)->getJson('/api/v1/admin/terms')->assertForbidden();
            $this->actingAs($user)->postJson('/api/v1/admin/terms', $this->payload())->assertForbidden();
        }

        foreach (['store-admin', 'super-admin'] as $role) {
            $this->actingAs($this->staff($role))->getJson('/api/v1/admin/terms')->assertOk()->assertJsonCount(10, 'data.sections');
        }

        $this->actingAs($this->staff('store-admin', ['mfa_totp_confirmed_at' => null]))->postJson('/api/v1/admin/terms', $this->payload())->assertForbidden();
        $this->assertSame(10, TermsSection::count());
    }

    public function test_create_update_and_delete_work_and_each_change_raises_the_version(): void
    {
        $admin = $this->staff('store-admin');
        $before = $this->version();

        $id = $this->actingAs($admin)->postJson('/api/v1/admin/terms', $this->payload())->assertCreated()->json('data.id');
        $created = $this->version();
        $this->assertNotSame($before, $created);
        $this->assertSame(11, TermsSection::count());
        $this->assertSame(TermsSection::max('sort_order'), TermsSection::find($id)->sort_order, 'butir baru di urutan terakhir');

        $this->actingAs($admin)->putJson("/api/v1/admin/terms/{$id}", $this->payload(['title_id' => 'Garansi rasa baru', 'is_published' => false]))->assertOk();
        $updated = $this->version();
        $this->assertNotSame($created, $updated);
        $this->assertSame(['Garansi rasa baru', false], [TermsSection::find($id)->title_id, TermsSection::find($id)->is_published]);

        $this->actingAs($admin)->deleteJson("/api/v1/admin/terms/{$id}")->assertOk();
        $this->assertNotSame($updated, $this->version());
        $this->assertNull(TermsSection::find($id));

        // Nomor urut harian naik satu per perubahan: .1 sudah dipakai migrasi, jadi tiga perubahan berikutnya .2 sampai .4.
        $this->assertSame(now()->format('Y-m-d').'.4', $this->version());
        $this->assertSame(
            ['terms.created', 'terms.updated', 'terms.deleted'],
            AuditLog::where('action', 'like', 'terms.%')->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_a_new_day_restarts_the_version_counter(): void
    {
        Setting::where('key', TermsService::VERSION_KEY)->update(['value' => now()->subDay()->format('Y-m-d').'.7']);

        $this->actingAs($this->staff('store-admin'))->postJson('/api/v1/admin/terms', $this->payload())->assertCreated();

        $this->assertSame(now()->format('Y-m-d').'.1', $this->version());
    }

    public function test_validation_rejects_empty_oversized_and_html_only_input(): void
    {
        $admin = $this->staff('store-admin');
        $version = $this->version();

        foreach (['title_id', 'title_en', 'body_id', 'body_en'] as $field) {
            $this->actingAs($admin)->postJson('/api/v1/admin/terms', $this->payload([$field => '']))->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->actingAs($admin)->postJson('/api/v1/admin/terms', $this->payload([$field => '<b></b>']))->assertUnprocessable();
        }
        $this->actingAs($admin)->postJson('/api/v1/admin/terms', $this->payload(['title_id' => str_repeat('a', 151)]))->assertUnprocessable()->assertJsonValidationErrors('title_id');
        $this->actingAs($admin)->postJson('/api/v1/admin/terms', $this->payload(['body_id' => str_repeat('a', 4001)]))->assertUnprocessable()->assertJsonValidationErrors('body_id');
        $this->actingAs($admin)->postJson('/api/v1/admin/terms', $this->payload(['is_published' => 'mungkin']))->assertUnprocessable();

        $this->assertSame(10, TermsSection::count());
        $this->assertSame($version, $this->version(), 'penolakan tidak menaikkan versi');
    }

    public function test_html_is_stripped_on_save_and_escaped_on_the_store(): void
    {
        $admin = $this->staff('store-admin');
        $id = $this->actingAs($admin)->postJson('/api/v1/admin/terms', $this->payload(['title_id' => 'Judul <i>miring</i>', 'body_id' => 'Isi <script>alert(1)</script>aman']))->assertCreated()->json('data.id');

        $this->assertSame('Judul miring', TermsSection::find($id)->title_id);
        $this->assertSame('Isi alert(1)aman', TermsSection::find($id)->body_id);

        // Lapis kedua: isi yang masuk lewat jalur lain (mis. langsung ke database) tetap di-escape saat tampil.
        TermsSection::find($id)->update(['body_id' => '<script>window.hacked=1</script><b>tebal</b>']);
        $html = $this->get('/kontak')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>window.hacked', $html);
        $this->assertStringContainsString('&lt;script&gt;window.hacked=1&lt;/script&gt;', $html);
    }

    public function test_move_swaps_neighbours_and_ignores_the_ends(): void
    {
        $admin = $this->staff('store-admin');
        $order = fn () => TermsSection::orderBy('sort_order')->orderBy('id')->pluck('title_id')->all();
        $original = $order();

        $this->actingAs($admin)->postJson('/api/v1/admin/terms/'.TermsSection::orderBy('sort_order')->skip(1)->first()->id.'/move', ['direction' => 'up'])->assertOk();
        $this->assertSame([$original[1], $original[0], ...array_slice($original, 2)], $order());

        $last = TermsSection::orderBy('sort_order')->orderBy('id')->get()->last();
        $this->actingAs($admin)->postJson("/api/v1/admin/terms/{$last->id}/move", ['direction' => 'down'])->assertOk();
        $this->assertSame($last->title_id, collect($order())->last(), 'butir terakhir tidak bisa turun lagi');

        $this->actingAs($admin)->postJson("/api/v1/admin/terms/{$last->id}/move", ['direction' => 'samping'])->assertUnprocessable();
    }

    public function test_the_contact_page_shows_published_sections_in_order_with_version_in_both_languages(): void
    {
        TermsSection::query()->delete();
        TermsSection::create(['sort_order' => 20, 'title_id' => 'Kedua', 'title_en' => 'Second', 'body_id' => "Baris A.\n\nBaris B.", 'body_en' => 'Line A.', 'is_published' => true]);
        TermsSection::create(['sort_order' => 10, 'title_id' => 'Pertama', 'title_en' => 'First', 'body_id' => 'Isi satu.', 'body_en' => 'Body one.', 'is_published' => true]);
        TermsSection::create(['sort_order' => 5, 'title_id' => 'Tersembunyi', 'title_en' => 'Hidden', 'body_id' => 'x', 'body_en' => 'x', 'is_published' => false]);

        $id = $this->get('/kontak')->assertOk();
        $id->assertSeeInOrder(['Pertama', 'Isi satu.', 'Kedua', 'Baris A.', 'Baris B.']);
        $id->assertDontSee('Tersembunyi');
        $id->assertSee('Versi '.$this->version(), false);
        $this->assertSame(2, substr_count($id->getContent(), '<p>Baris'), 'baris kosong memisahkan paragraf');

        $this->get('/kontak?lang=en')->assertOk()->assertSeeInOrder(['First', 'Body one.', 'Second', 'Line A.'])->assertSee('Version '.$this->version(), false)->assertDontSee('Hidden');
    }

    public function test_the_contact_page_falls_back_to_the_built_in_text_when_nothing_is_published(): void
    {
        TermsSection::query()->update(['is_published' => false]);

        $this->get('/kontak')->assertOk()->assertSee('Ketentuan umum')->assertDontSee('tf-legal-version', false);
    }

    public function test_refund_requests_record_the_current_terms_version(): void
    {
        $admin = $this->staff('store-admin');
        $this->actingAs($admin)->postJson('/api/v1/admin/terms', $this->payload())->assertCreated();
        $version = $this->version();

        $this->assertSame($version, (string) Setting::where('key', 'refund.terms_version')->value('value'));
    }
}
