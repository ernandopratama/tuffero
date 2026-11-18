<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\TermsSection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Kelola butir Syarat dan Ketentuan dan Kebijakan Privasi toko (Admin Toko dan Super Admin, D-139). Setiap perubahan, termasuk urutan
 * dan status tayang, menaikkan versi jenisnya: S&K di setting `refund.terms_version` (dicatat di tiap pengajuan refund sebagai S&K yang
 * disetujui pelanggan), Privasi di `privacy.version`. Versi Privasi tidak pernah menyentuh versi S&K. Urutan berlaku per jenis.
 * Semua perubahan tercatat di audit log (tanpa isi lengkap, hanya judul dan versi).
 */
class TermsService
{
    public const VERSION_KEY = 'refund.terms_version';

    public const PRIVACY_VERSION_KEY = 'privacy.version';

    public static function versionKey(string $type): string
    {
        return $type === TermsSection::PRIVACY ? self::PRIVACY_VERSION_KEY : self::VERSION_KEY;
    }

    public function version(string $type = TermsSection::TERMS): string
    {
        return (string) (Setting::where('key', self::versionKey($type))->value('value') ?: 'draf');
    }

    /** @param array{title_id: string, title_en: string, body_id: string, body_en: string, is_published: bool} $data */
    public function create(array $data, User $actor, string $type = TermsSection::TERMS): TermsSection
    {
        return DB::transaction(function () use ($data, $actor, $type) {
            $section = TermsSection::create($data + ['type' => $type, 'sort_order' => ((int) TermsSection::where('type', $type)->max('sort_order')) + 10, 'updated_by_user_id' => $actor->id]);
            $this->recorded('created', $section, $actor);

            return $section;
        });
    }

    /** @param array{title_id: string, title_en: string, body_id: string, body_en: string, is_published: bool} $data */
    public function update(TermsSection $section, array $data, User $actor): TermsSection
    {
        return DB::transaction(function () use ($section, $data, $actor) {
            $section->update($data + ['updated_by_user_id' => $actor->id]);
            $this->recorded('updated', $section, $actor);

            return $section;
        });
    }

    public function delete(TermsSection $section, User $actor): void
    {
        DB::transaction(function () use ($section, $actor) {
            $this->recorded('deleted', $section, $actor);
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
            $ordered = TermsSection::where('type', $section->type)->orderBy('sort_order')->orderBy('id')->lockForUpdate()->get();

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
            $this->recorded('moved', $section, $actor);
        });
    }

    /** Versi berikutnya: tanggal hari ini ditambah nomor urut harian, mis. 2026-11-13.3. */
    private function bump(string $type): string
    {
        $today = now()->format('Y-m-d');
        $current = $this->version($type);
        $next = str_starts_with($current, $today.'.') ? $today.'.'.(((int) substr($current, strlen($today) + 1)) + 1) : $today.'.1';

        Setting::updateOrCreate(['key' => self::versionKey($type)], ['value' => $next, 'type' => 'string', 'group' => $type === TermsSection::PRIVACY ? 'privacy' : 'refund', 'is_public' => false]);

        return $next;
    }

    /** Aksi audit: terms.created dan seterusnya untuk S&K, privacy.created dan seterusnya untuk Privasi. */
    private function recorded(string $action, TermsSection $section, User $actor): void
    {
        $version = $this->bump($section->type);
        $prefix = $section->type === TermsSection::PRIVACY ? 'privacy' : 'terms';
        AuditLog::record("{$prefix}.{$action}", $section, ['title' => $section->title_id, 'published' => $section->is_published, 'version' => $version], $actor->id);
    }
}
