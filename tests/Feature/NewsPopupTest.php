<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\NewsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Popup berita di beranda toko (D-085): isi, urutan, batas 5, bahasa, dan keamanan data. */
class NewsPopupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function news(array $attributes = []): NewsItem
    {
        return NewsItem::create(['kind' => 'news', 'title' => 'Kabar', 'body' => 'Isi kabar', 'is_published' => true, ...$attributes]);
    }

    private function campaign(array $attributes = []): Campaign
    {
        $campaign = new Campaign(['title' => 'Flash Sale', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(5), 'is_flash' => true, 'is_active' => true, ...$attributes]);
        $campaign->forceFill(['slug' => 'flash-'.uniqid()])->save();

        return $campaign;
    }

    /** @return array{version: string, css: string, items: list<array<string, mixed>>, words: array<string, string>}|null */
    private function popup(string $uri = '/'): ?array
    {
        $html = $this->get($uri)->assertOk()->getContent();

        return preg_match('~<script type="application/json" id="tf-news-data">(.*?)</script>~s', $html, $m) ? json_decode($m[1], true, 512, JSON_THROW_ON_ERROR) : null;
    }

    public function test_home_has_no_popup_data_when_nothing_is_live(): void
    {
        $this->news(['is_published' => false]);
        $this->news(['ends_at' => now()->subDay()]);
        $this->news(['starts_at' => now()->addDay()]);
        $this->campaign(['is_active' => false]);
        $this->campaign(['starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);

        $this->assertNull($this->popup());
    }

    public function test_popup_lists_live_campaign_first_then_news_then_upcoming_campaign(): void
    {
        $this->news(['title' => 'Berita B', 'sort_order' => 2]);
        $this->news(['title' => 'Berita A', 'sort_order' => 1]);
        $this->campaign(['title' => 'Segera', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(3), 'is_flash' => false]);
        $this->campaign(['title' => 'Sedang Jalan']);

        $titles = array_column($this->popup()['items'], 'title');

        $this->assertSame(['Sedang Jalan', 'Berita A', 'Berita B', 'Segera'], $titles);
    }

    public function test_popup_is_limited_to_five_items(): void
    {
        foreach (range(1, 4) as $i) {
            $this->news(['title' => "Berita {$i}", 'sort_order' => $i]);
        }
        $this->campaign(['title' => 'K1']);
        $this->campaign(['title' => 'K2']);

        $items = $this->popup()['items'];

        $this->assertCount(5, $items);
        $this->assertSame(['K1', 'K2'], array_slice(array_column($items, 'title'), 0, 2), 'kampanye berjalan diprioritaskan');
        $this->assertNotContains('Berita 4', array_column($items, 'title'), 'yang melewati batas tidak tampil');
    }

    public function test_campaign_item_links_to_its_promo_page(): void
    {
        $campaign = $this->campaign(['title' => 'Akhir Pekan']);

        $item = $this->popup()['items'][0];

        $this->assertSame('/promo/'.$campaign->slug, $item['cta']['url']);
        $this->assertSame('Flash Sale', $item['badge']);
    }

    public function test_news_link_appears_only_when_label_and_url_exist(): void
    {
        $this->news(['title' => 'Dengan tautan', 'cta_label' => 'Lihat', 'cta_url' => '/produk', 'sort_order' => 1]);
        $this->news(['title' => 'Tanpa tautan', 'cta_url' => '/produk', 'sort_order' => 2]);

        $items = $this->popup()['items'];

        $this->assertSame('/produk', $items[0]['cta']['url']);
        $this->assertNull($items[1]['cta']);
    }

    public function test_english_uses_english_text_and_falls_back_to_indonesian(): void
    {
        $this->news(['title' => 'Judul', 'title_en' => 'Title', 'body' => 'Isi', 'body_en' => null, 'kind' => 'update', 'sort_order' => 1]);

        $items = $this->popup('/?lang=en')['items'];

        $this->assertSame('Title', $items[0]['title']);
        $this->assertSame('Isi', $items[0]['body']);
        $this->assertSame('Update', $items[0]['badge']);
        $this->assertSame('News from Tuffero', $this->popup('/?lang=en')['words']['heading']);
    }

    public function test_content_cannot_break_out_of_the_json_script_tag(): void
    {
        $this->news(['title' => '</script><script>alert(1)</script>', 'body' => '"><img src=x onerror=alert(1)>']);

        $html = $this->get('/')->getContent();

        $this->assertStringNotContainsString('</script><script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertSame('</script><script>alert(1)</script>', $this->popup()['items'][0]['title'], 'data tetap utuh setelah di-decode');
    }

    public function test_popup_data_does_not_expose_internal_fields_and_version_changes_with_content(): void
    {
        $news = $this->news();
        $before = $this->popup();
        $this->assertArrayNotHasKey('stamp', $before['items'][0]);

        $this->travel(5)->seconds();
        $news->update(['title' => 'Diubah']);

        $this->assertNotSame($before['version'], $this->popup()['version']);
    }

    public function test_other_pages_do_not_carry_popup_data(): void
    {
        $this->news();

        $this->assertNull($this->popup('/produk'));
    }
}
