<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\MarketingDraft;
use App\Services\MarketingDraftService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Draf Konten contoh yang sama di lokal dan dev: 11 draf, meniru hasil Hermes: 4 riset tren (mingguan dan "Pantau Kompetitor", satu per
 * job per hari) dan 7 rencana video (dari kalender Senin dan satu dari job Minggu),
 * data/content_drafts.json). Semua isian form terisi: jadwal posting (relatif terhadap Senin minggu ini), platform, durasi, musik,
 * dan sumber visual (satu draf bermode AI penuh untuk mencoba unggah foto acuan). Caption tidak ikut: lihat Generator Caption. Tidak dipanggil DatabaseSeeder. Jalankan dengan php artisan db:seed --class=ContentDraftSeeder.
 *
 * - Draf dibuat lewat MarketingDraftService::create seperti kiriman Hermes sungguhan (origin hermes, status draf, tanpa tanggal
 *   jadwal; draf video ikut diantre untuk storyboard). external_id berawalan "seed:" supaya tidak bentrok dengan kiriman Hermes.
 * - Judul berawalan [Seeder] dan bernomor tetap 1 sampai 11, berurutan menurut tanggal jadwal (terkecil dulu) (kolom `number`, tampil sebagai "Draf #1"), sama di semua database.
 * - Aman dijalankan ulang: draf yang sudah ada (termasuk yang dihapus) tidak dibuat ulang, jadi status yang Anda ubah tidak tertimpa;
 *   hanya awalan judul dan nomornya yang dirapikan.
 * - Sebelum membuat, kiriman Hermes ASLI dengan external_id dari berkas data dihapus ringan bila masih ada, begitu pula draf
 *   seeder lama berjenis caption, supaya hasilnya sama di semua database. Kiriman Hermes baru dengan external_id lain tidak disentuh.
 * - Ditolak di production: ini data contoh, dan penghapusan di atas tidak boleh menyentuh draf asli.
 */
class ContentDraftSeeder extends Seeder
{
    private const TAG = '[Seeder] ';

    public function run(MarketingDraftService $drafts): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('ContentDraftSeeder dilewati: hanya untuk lokal dan dev, bukan production.');

            return;
        }

        $rows = json_decode(File::get(database_path('seeders/data/content_drafts.json')), true, flags: JSON_THROW_ON_ERROR);

        // Nomor Draf mengikuti urutan jadwal: tanggal terkecil dulu. Pada tanggal yang sama riset tren mendahului rencana video,
        // lalu urutan di berkas data. Urutan berkas tidak menentukan nomor; usort stabil sejak PHP 8.
        usort($rows, fn (array $x, array $y) => [$x['day_offset'], $x['type'] !== 'trend_report'] <=> [$y['day_offset'], $y['type'] !== 'trend_report']);

        $removed = 0;
        foreach (MarketingDraft::where('origin', MarketingDraft::HERMES)->whereIn('external_id', array_column($rows, 'legacy_external_id'))->get() as $old) {
            AuditLog::record('marketing_draft.deleted', $old, ['title' => $old->title, 'via' => 'ContentDraftSeeder'], null);
            $old->delete();
            $removed++;
        }

        // Caption dan kalender konten sudah bukan draf (caption pindah ke Generator Caption): sisa draf seeder lama berjenis itu dihapus ringan.
        foreach (MarketingDraft::whereIn('type', ['caption', 'calendar_item'])->where('external_id', 'like', 'seed:%')->get() as $retired) {
            AuditLog::record('marketing_draft.deleted', $retired, ['title' => $retired->title, 'via' => 'ContentDraftSeeder'], null);
            $retired->forceFill(['number' => null])->saveQuietly();
            $retired->delete();
            $removed++;
        }

        // Nomor 1 sampai N dan awalan [Seeder] dipasang di semua database, supaya "Draf #3" yang sama di lokal dan dev.
        $ids = array_map(fn (array $row) => 'seed:'.$row['legacy_external_id'], $rows);
        MarketingDraft::onlyTrashed()->whereBetween('number', [1, count($rows)])->whereNotIn('external_id', $ids)->update(['number' => null]);

        // Jadwal posting dihitung dari Senin minggu ini (day_offset 0 = Senin; negatif = minggu lalu), jadi kalender selalu terisi minggu berjalan dan berikutnya.
        $monday = now()->startOfWeek()->toImmutable(); // immutable: addDays tidak boleh menggeser Senin itu sendiri

        // Tahap 1: buat atau rapikan judulnya, dan lepaskan nomor yang salah supaya tahap 2 tidak saling bentrok.
        $created = 0;
        $seeded = [];
        foreach ($rows as $index => $row) {
            $draft = MarketingDraft::withTrashed()->where('external_id', $ids[$index])->first();

            if (! $draft) {
                $date = $monday->addDays($row['day_offset']);
                $trend = $row['type'] === 'trend_report';
                $draft = $drafts->create(
                    [
                        // Riset: judul bertanggal jadwal dan periode data 7 hari sebelumnya, supaya selaras di minggu mana pun seeder dijalankan.
                        'title' => self::TAG.$row['title'].($trend ? ' '.$date->locale('id')->isoFormat('D MMM YYYY') : ''),
                        ...array_intersect_key($row, array_flip(['type', 'platform', 'body', 'sources', 'source_period', 'duration_seconds', 'music_note', 'visual_mode'])),
                        ...($trend ? ['source_period' => $date->subDays(7)->locale('id')->isoFormat('DD MMM YYYY').' sampai '.$date->locale('id')->isoFormat('DD MMM YYYY')] : []),
                        'scheduled_on' => $date->toDateString(),
                    ],
                    null,
                    MarketingDraft::HERMES,
                    $ids[$index],
                );
                $created++;
            } elseif (! str_starts_with($draft->title, self::TAG)) {
                $draft->forceFill(['title' => self::TAG.$draft->title])->saveQuietly();
            }

            if ($draft->number !== $index + 1) {
                $draft->forceFill(['number' => null])->saveQuietly();
            }
            $seeded[$index] = $draft;
        }

        // Tahap 2: pasang nomor 1 sampai N. Nomor yang dipegang draf lain yang masih aktif tidak direbut; draf seeder itu
        // mendapat nomor berikutnya yang kosong.
        $renumbered = 0;
        foreach ($seeded as $index => $draft) {
            if ($draft->number !== null) {
                continue;
            }

            $number = $index + 1;
            $held = MarketingDraft::withTrashed()->where('number', $number)->whereKeyNot($draft->id)->first();
            if ($held && ! $held->trashed()) {
                $this->command?->warn("Nomor {$number} dipakai draf lain ({$held->title}); draf seeder itu diberi nomor berikutnya.");
                $number = (int) MarketingDraft::withTrashed()->max('number') + 1;
            } else {
                $held?->forceFill(['number' => null])->saveQuietly();
            }

            $draft->forceFill(['number' => $number])->saveQuietly();
            $renumbered++;
        }
        $this->command?->info("{$created} draf dibuat, {$renumbered} diberi nomor, {$removed} draf Hermes lama dihapus.");
    }
}
