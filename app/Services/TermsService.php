<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\TermsSection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Kelola butir Syarat dan Ketentuan toko (Admin Toko dan Super Admin). Setiap perubahan, termasuk urutan dan status tayang, menaikkan
 * versi di setting `refund.terms_version`, dan versi itu dicatat di tiap pengajuan refund sebagai S&K yang disetujui pelanggan.
 * Semua perubahan tercatat di audit log (tanpa isi lengkap, hanya judul dan versi).
 */
class TermsService
{
    public const VERSION_KEY = 'refund.terms_version';

    public function version(): string
    {
        return (string) (Setting::where('key', self::VERSION_KEY)->value('value') ?: 'draf');
    }

    /** @param array{title_id: string, title_en: string, body_id: string, body_en: string, is_published: bool} $data */
    public function create(array $data, User $actor): TermsSection
    {
        return DB::transaction(function () use ($data, $actor) {
            $section = TermsSection::create($data + ['sort_order' => ((int) TermsSection::max('sort_order')) + 10, 'updated_by_user_id' => $actor->id]);
            $this->recorded('terms.created', $section, $actor);

            return $section;
        });
    }

    /** @param array{title_id: string, title_en: string, body_id: string, body_en: string, is_published: bool} $data */
    public function update(TermsSection $section, array $data, User $actor): TermsSection
    {
        return DB::transaction(function () use ($section, $data, $actor) {
            $section->update($data + ['updated_by_user_id' => $actor->id]);
            $this->recorded('terms.updated', $section, $actor);

            return $section;
        });
    }

    public function delete(TermsSection $section, User $actor): void
    {
        DB::transaction(function () use ($section, $actor) {
            $this->recorded('terms.deleted', $section, $actor);
            $section->delete();
        });
    }

    /**
     * Tukar urutan dengan tetangganya. Nomor urut disusun ulang rapat dulu supaya dua butir dengan nomor sama tidak membuat
     * tombol naik atau turun tidak berefek.
     */
    public function move(TermsSection $section, string $direction, User $actor): void
    {
        DB::transaction(function () use ($section, $direction, $actor) {
            $ordered = TermsSection::orderBy('sort_order')->orderBy('id')->lockForUpdate()->get();

            foreach ($ordered as $i => $row) {
                $row->sort_order = ($i + 1) * 10;
            }

            $index = $ordered->search(fn (TermsSection $row) => $row->id === $section->id);
            $swap = $direction === 'up' ? $index - 1 : $index + 1;

            if ($index === false || ! isset($ordered[$swap])) {
                return;
            }

            [$ordered[$index]->sort_order, $ordered[$swap]->sort_order] = [$ordered[$swap]->sort_order, $ordered[$index]->sort_order];
            $ordered->each->save();
            $this->recorded('terms.moved', $section, $actor);
        });
    }

    /** Versi berikutnya: tanggal hari ini ditambah nomor urut harian, mis. 2026-11-13.3. */
    private function bump(): string
    {
        $today = now()->format('Y-m-d');
        $current = $this->version();
        $next = str_starts_with($current, $today.'.') ? $today.'.'.(((int) substr($current, strlen($today) + 1)) + 1) : $today.'.1';

        Setting::updateOrCreate(['key' => self::VERSION_KEY], ['value' => $next, 'type' => 'string', 'group' => 'refund', 'is_public' => false]);

        return $next;
    }

    private function recorded(string $action, TermsSection $section, User $actor): void
    {
        $version = $this->bump();
        AuditLog::record($action, $section, ['title' => $section->title_id, 'published' => $section->is_published, 'version' => $version], $actor->id);
    }
}
