<?php

namespace Tests\Feature;

use App\Models\MarketingDraft;
use App\Services\MarketingDraftService;
use Carbon\CarbonImmutable;
use Database\Seeders\ContentDraftResetSeeder;
use Database\Seeders\ContentDraftSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContentDraftSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_seeds_the_eleven_hermes_drafts_once_and_keeps_later_changes(): void
    {
        $this->seed(ContentDraftSeeder::class);
        $this->seed(ContentDraftSeeder::class);

        $this->assertSame(11, MarketingDraft::count());
        $this->assertSame(11, MarketingDraft::where('origin', 'hermes')->where('status', 'draft')->count());
        $this->assertSame(7, MarketingDraft::where('type', 'video_script')->count());
        $this->assertSame(0, MarketingDraft::whereIn('type', ['caption', 'calendar_item'])->count(), 'Caption tidak lagi draf');
        $this->assertSame(4, MarketingDraft::where('type', 'trend_report')->whereNotNull('sources')->count());

        $this->assertSame(range(1, 11), MarketingDraft::orderBy('number')->pluck('number')->all());
        $this->assertSame(11, MarketingDraft::where('title', 'like', '[Seeder] %')->count());

        $draft = MarketingDraft::where('number', 4)->firstOrFail();
        $draft->forceFill(['status' => 'approved'])->save();
        $draft->delete();
        $this->seed(ContentDraftSeeder::class);

        $this->assertSame(10, MarketingDraft::count(), 'Draf yang dihapus tidak dibuat ulang');
        $this->assertSame(4, MarketingDraft::withTrashed()->whereKey($draft->id)->value('number'), 'Nomor tetap');
    }

    public function test_new_drafts_continue_the_numbering_and_old_numbers_are_freed(): void
    {
        $drafts = app(MarketingDraftService::class);
        $first = $drafts->create(['type' => 'caption', 'platform' => 'instagram', 'title' => 'A', 'body' => 'a'], null);
        $second = $drafts->create(['type' => 'caption', 'platform' => 'instagram', 'title' => 'B', 'body' => 'b'], null);
        $this->assertSame([1, 2], [$first->number, $second->number]);

        $first->delete();
        $third = $drafts->create(['type' => 'caption', 'platform' => 'instagram', 'title' => 'C', 'body' => 'c'], null);
        $this->assertSame(3, $third->number, 'Nomor draf yang dihapus tidak dipakai ulang');

        $this->seed(ContentDraftSeeder::class);

        $seeded = MarketingDraft::where('title', 'like', '[Seeder] %')->pluck('number')->all();
        $this->assertCount(11, array_unique($seeded), 'Nomor seeder unik');
        $this->assertSame([2, 3], MarketingDraft::whereIn('title', ['B', 'C'])->orderBy('number')->pluck('number')->all(), 'Nomor draf aktif lain tidak direbut');
        $this->assertContains(1, $seeded);
    }

    public function test_old_seeded_captions_are_retired_and_numbers_close_up(): void
    {
        $drafts = app(MarketingDraftService::class);
        $caption = $drafts->create(['type' => 'caption', 'platform' => 'instagram', 'title' => '[Seeder] Selai nanas homemade', 'body' => 'x'], null, 'hermes', 'seed:bed7c0ad3a34:2026-11-16_08-18-17.md:k3');

        $this->seed(ContentDraftSeeder::class);

        $this->assertNull(MarketingDraft::find($caption->id));
        $this->assertSame(range(1, 11), MarketingDraft::orderBy('number')->pluck('number')->all());
    }

    public function test_every_form_field_is_filled_and_the_dates_follow_this_week(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00:00')); // Kamis; Senin minggu ini = 16 Nov

        $this->seed(ContentDraftSeeder::class);

        $this->assertSame(0, MarketingDraft::whereNull('scheduled_on')->count(), 'Semua draf punya jadwal');
        $dates = MarketingDraft::orderBy('number')->pluck('scheduled_on')->map->toDateString();
        $this->assertSame('2026-11-09', $dates->min());
        $this->assertSame('2026-11-19', $dates->max());

        $videos = MarketingDraft::where('type', 'video_script')->get();
        $this->assertCount(7, $videos);
        foreach ($videos as $video) {
            $this->assertNotNull($video->platform, $video->title);
            $this->assertContains($video->duration_seconds, MarketingDraft::DURATIONS, $video->title);
            $this->assertNotEmpty($video->music_note, $video->title);
            $this->assertContains($video->visual_mode, array_keys(MarketingDraft::VISUAL_MODES), $video->title);
        }
        $this->assertSame(['instagram', 'tiktok'], $videos->pluck('platform')->unique()->sort()->values()->all());
        $this->assertSame(1, $videos->where('visual_mode', 'ai')->count(), 'Satu draf mode AI penuh untuk uji foto acuan');
        foreach (MarketingDraft::where('type', 'trend_report')->get() as $trend) {
            $this->assertNotEmpty($trend->sources);
            $this->assertNotEmpty($trend->source_period);
        }
    }

    public function test_the_reset_seeder_wipes_every_draft_and_starts_over_at_number_one(): void
    {
        $drafts = app(MarketingDraftService::class);
        $this->seed(ContentDraftSeeder::class);
        MarketingDraft::where('number', 3)->firstOrFail()->forceFill(['status' => 'approved'])->save();
        MarketingDraft::where('number', 5)->firstOrFail()->delete();
        $own = $drafts->create(['type' => 'video_script', 'platform' => 'tiktok', 'title' => 'Draf saya sendiri', 'body' => 'x'], null);

        $this->seed(ContentDraftResetSeeder::class);

        $this->assertSame(11, MarketingDraft::withTrashed()->count());
        $this->assertSame(0, MarketingDraft::where('title', 'Draf saya sendiri')->count());
        $this->assertSame(range(1, 11), MarketingDraft::orderBy('number')->pluck('number')->all());
        $this->assertSame(11, MarketingDraft::where('status', 'draft')->count());
        $this->assertNull(MarketingDraft::find($own->id));
    }

    public function test_draft_numbers_follow_the_schedule_from_the_earliest_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00:00'));

        $this->seed(ContentDraftSeeder::class);

        $drafts = MarketingDraft::orderBy('number')->get();
        $dates = $drafts->map(fn (MarketingDraft $draft) => $draft->scheduled_on->toDateString())->all();
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates, 'Nomor naik berarti tanggal tidak turun');
        $this->assertSame('2026-11-09', $dates[0]);
        $this->assertSame('2026-11-19', end($dates));
        // Tanggal = hari job berjalan: Senin, Kamis, atau Minggu (Jumat hanya pengingat, tanpa draf).
        foreach ($dates as $date) {
            $this->assertContains(CarbonImmutable::parse($date)->dayOfWeek, [0, 1, 4], $date);
        }

        // Isi per hari: Senin = 1 riset tren + 2 sampai 8 rencana video; Kamis = hanya 1 Pantau Kompetitor; Minggu = hanya 1 rencana video.
        foreach ($drafts->groupBy(fn (MarketingDraft $draft) => $draft->scheduled_on->toDateString()) as $date => $sameDay) {
            $day = CarbonImmutable::parse($date)->dayOfWeek;
            $trends = $sameDay->where('type', 'trend_report');
            $videos = $sameDay->where('type', 'video_script');

            match ($day) {
                1 => [$this->assertCount(1, $trends, "Senin {$date}: 1 riset tren"), $this->assertGreaterThanOrEqual(2, $videos->count()), $this->assertLessThanOrEqual(8, $videos->count())],
                4 => [$this->assertCount(1, $sameDay, "Kamis {$date}: 1 draf"), $this->assertStringContainsString('Pantau Kompetitor', $sameDay->first()->title)],
                0 => [$this->assertCount(1, $sameDay, "Minggu {$date}: 1 draf"), $this->assertSame('video_script', $sameDay->first()->type)],
            };
        }
        // Tanggal yang sama: riset tren lebih dulu daripada rencana video.
        foreach ($drafts->groupBy(fn (MarketingDraft $draft) => $draft->scheduled_on->toDateString()) as $sameDay) {
            $types = $sameDay->pluck('type')->all();
            $firstVideo = array_search('video_script', $types, true);
            $lastTrend = array_key_last(array_filter($types, fn (string $type) => $type === 'trend_report'));
            $this->assertTrue($firstVideo === false || $lastTrend === null || $lastTrend < $firstVideo);
        }
    }

    public function test_research_titles_and_periods_follow_the_schedule_in_any_week(): void
    {
        foreach (['2026-11-19 10:00:00' => ['9 Nov 2026', '12 Nov 2026', '16 Nov 2026', '19 Nov 2026'], '2026-12-08 10:00:00' => ['30 Nov 2026', '3 Des 2026', '7 Des 2026', '10 Des 2026']] as $now => $expected) {
            $this->travelTo(CarbonImmutable::parse($now));
            MarketingDraft::withTrashed()->forceDelete();

            $this->seed(ContentDraftSeeder::class);

            $research = MarketingDraft::where('type', 'trend_report')->orderBy('scheduled_on')->get();
            $this->assertSame(
                array_map(fn (string $date) => '[Seeder] '.(str_starts_with($date, '9 Nov') || str_starts_with($date, '16 Nov') || str_starts_with($date, '30 Nov') || str_starts_with($date, '7 Des') ? 'Riset tren mingguan ' : 'Pantau Kompetitor ').$date, $expected),
                $research->pluck('title')->all(),
            );
            foreach ($research as $draft) {
                $end = CarbonImmutable::parse($draft->scheduled_on)->locale('id');
                $this->assertSame($end->subDays(7)->isoFormat('DD MMM YYYY').' sampai '.$end->isoFormat('DD MMM YYYY'), $draft->source_period, 'Periode data = 7 hari sampai tanggal jadwal');
            }
        }
    }

    public function test_there_is_one_research_draft_per_job_per_day(): void
    {
        $this->seed(ContentDraftSeeder::class);

        $titles = MarketingDraft::where('type', 'trend_report')->pluck('title');
        $this->assertSame($titles->count(), $titles->unique()->count(), 'Judul riset tidak kembar');
        $this->assertSame(4, $titles->count());
        $this->assertSame(2, $titles->filter(fn (string $title) => str_starts_with($title, '[Seeder] Pantau Kompetitor '))->count(), 'Nama job Kamis: Pantau Kompetitor');
        $this->assertSame(1, MarketingDraft::where('type', 'video_script')->where('title', 'like', '%Beri Tahu Saya%')->count(), 'Job Minggu: satu Rencana Video');
    }

    public function test_removes_old_hermes_drafts_from_the_data_file_but_not_newer_ones(): void
    {
        $drafts = app(MarketingDraftService::class);
        $old = $drafts->create(['type' => 'trend_report', 'title' => 'Lama', 'body' => 'x', 'sources' => 's', 'source_period' => 'p'], null, 'hermes', 'bed7c0ad3a34:2026-11-14_09-11-05.md');
        $new = $drafts->create(['type' => 'caption', 'platform' => 'instagram', 'title' => 'Baru', 'body' => 'y'], null, 'hermes', 'bed7c0ad3a34:2026-11-30_08-00-00.md');
        $manual = $drafts->create(['type' => 'caption', 'platform' => 'instagram', 'title' => 'Manual', 'body' => 'z'], null);

        $this->seed(ContentDraftSeeder::class);

        $this->assertNull(MarketingDraft::find($old->id));
        $this->assertNotNull(MarketingDraft::find($new->id));
        $this->assertNotNull(MarketingDraft::find($manual->id));
    }

    public function test_is_refused_in_production(): void
    {
        $this->app['env'] = 'production';

        // Langsung, bukan lewat db:seed: perintah itu meminta konfirmasi interaktif di production.
        (new ContentDraftSeeder)->run(app(MarketingDraftService::class));

        $this->assertSame(0, MarketingDraft::count());
    }
}
