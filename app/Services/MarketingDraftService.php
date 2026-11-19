<?php

namespace App\Services;

use App\Jobs\GenerateStoryboard;
use App\Models\AuditLog;
use App\Models\MarketingDraft;
use App\Models\MarketingDraftNote;
use App\Models\MarketingDraftReference;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\StaffEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Alur draf konten: draft → in_review → approved atau rejected → posted. Tidak ada publikasi otomatis (BR-19).
 * Setiap perpindahan memeriksa status terkini dengan kunci baris, supaya dua orang yang menekan tombol bersamaan tidak saling menimpa.
 */
class MarketingDraftService
{
    public function __construct(private StaffNotifier $notifier, private ImageService $images) {}

    /** Perpindahan oleh Marketing (`marketing_drafts.manage`): aksi => [status asal, status tujuan]. */
    private const MOVES = [
        'submit' => [[MarketingDraft::DRAFT, MarketingDraft::REJECTED], MarketingDraft::IN_REVIEW],
        'withdraw' => [[MarketingDraft::IN_REVIEW], MarketingDraft::DRAFT],
        'post' => [[MarketingDraft::APPROVED], MarketingDraft::POSTED],
    ];

    /**
     * @param  array<string, mixed>  $data
     * @param  string  $origin  `manual`, `ai`, atau `hermes`; tidak pernah dari input pengguna
     */
    public function create(array $data, ?User $actor, string $origin = MarketingDraft::MANUAL, ?string $externalId = null): MarketingDraft
    {
        return DB::transaction(function () use ($data, $actor, $origin, $externalId) {
            $draft = new MarketingDraft($data);
            // Nomor tertinggi dikunci supaya dua draf baru bersamaan tidak mendapat nomor yang sama (indeks unik sebagai pengaman).
            $number = (int) MarketingDraft::withTrashed()->orderByDesc('number')->lockForUpdate()->value('number') + 1;
            $draft->forceFill(['number' => $number, 'status' => MarketingDraft::DRAFT, 'origin' => $origin, 'external_id' => $externalId, 'created_by_user_id' => $actor?->id])->save();

            AuditLog::record('marketing_draft.created', $draft, ['title' => $draft->title, 'type' => $draft->type, 'origin' => $origin], $actor?->id);

            // Draf video (termasuk kiriman Hermes hari Senin) langsung dibuatkan naskah dan storyboard di antrean.
            $this->queueStoryboard($draft);

            return $draft->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(MarketingDraft $draft, array $data, User $actor): MarketingDraft
    {
        return DB::transaction(function () use ($draft, $data, $actor) {
            $draft = MarketingDraft::lockForUpdate()->findOrFail($draft->id);
            $this->guard($draft, MarketingDraft::EDITABLE, 'Draf yang sedang ditinjau atau sudah disetujui tidak bisa diubah. Tarik kembali dulu.');
            $this->reopenIfRejected($draft);

            $before = $draft->only(['type', 'duration_seconds', 'music_note', 'visual_mode']);
            AuditLog::saveWithAudit($draft, $data, 'marketing_draft.updated', $actor);

            // Susun ulang hanya bila yang memengaruhi storyboard berubah, atau draf video ini belum punya storyboard.
            if ($before !== $draft->only(['type', 'duration_seconds', 'music_note', 'visual_mode']) || $draft->storyboard_status === null) {
                $this->queueStoryboard($draft);
            }

            return $draft->refresh();
        });
    }

    /** Catatan tim untuk AI; setiap catatan menyusun ulang storyboard. */
    public function addNote(MarketingDraft $draft, string $body, User $actor): MarketingDraft
    {
        return DB::transaction(function () use ($draft, $body, $actor) {
            $draft = $this->lockVideoDraft($draft);
            $draft->notes()->create(['body' => $body, 'user_id' => $actor->id]);
            AuditLog::record('marketing_draft.note_added', $draft, [], $actor->id);
            $this->queueStoryboard($draft);

            return $draft->refresh();
        });
    }

    /** Catatan boleh diubah atau dihapus Marketing dan Super Admin selama draf masih bisa disunting; storyboard ikut disusun ulang. */
    public function updateNote(MarketingDraft $draft, MarketingDraftNote $note, string $body, User $actor): MarketingDraft
    {
        return DB::transaction(function () use ($draft, $note, $body, $actor) {
            $draft = $this->lockVideoDraft($draft);
            $note->update(['body' => $body]);
            AuditLog::record('marketing_draft.note_updated', $draft, ['note_id' => $note->id], $actor->id);
            $this->queueStoryboard($draft);

            return $draft->refresh();
        });
    }

    public function deleteNote(MarketingDraft $draft, MarketingDraftNote $note, User $actor): MarketingDraft
    {
        return DB::transaction(function () use ($draft, $note, $actor) {
            $draft = $this->lockVideoDraft($draft);
            $note->delete();
            AuditLog::record('marketing_draft.note_deleted', $draft, ['note_id' => $note->id], $actor->id);
            $this->queueStoryboard($draft);

            return $draft->refresh();
        });
    }

    /** Foto acuan disimpan ulang sebagai WebP di disk privat (EXIF dan lokasi dibuang), lalu storyboard disusun ulang. */
    public function addReference(MarketingDraft $draft, UploadedFile $file, User $actor): MarketingDraft
    {
        return DB::transaction(function () use ($draft, $file, $actor) {
            $draft = $this->lockVideoDraft($draft);
            if ($draft->references()->count() >= MarketingDraft::MAX_REFERENCES) {
                throw ValidationException::withMessages(['image' => 'Maksimal '.MarketingDraft::MAX_REFERENCES.' foto acuan per draf.']);
            }

            $path = "marketing-references/{$draft->id}/".Str::random(32).'.webp';
            Storage::disk('local')->put($path, $this->images->toWebp($file, 1568, 85));
            $draft->references()->create(['path' => $path, 'uploaded_by_user_id' => $actor->id]);
            AuditLog::record('marketing_draft.reference_added', $draft, [], $actor->id);
            $this->queueStoryboard($draft);

            return $draft->refresh();
        });
    }

    public function deleteReference(MarketingDraft $draft, MarketingDraftReference $reference, User $actor): MarketingDraft
    {
        return DB::transaction(function () use ($draft, $reference, $actor) {
            $draft = $this->lockVideoDraft($draft);
            Storage::disk('local')->delete($reference->path);
            $reference->delete();
            AuditLog::record('marketing_draft.reference_deleted', $draft, [], $actor->id);
            $this->queueStoryboard($draft);

            return $draft->refresh();
        });
    }

    public function move(MarketingDraft $draft, string $action, User $actor): MarketingDraft
    {
        [$from, $to] = self::MOVES[$action];

        return DB::transaction(function () use ($draft, $action, $from, $to, $actor) {
            $draft = MarketingDraft::lockForUpdate()->findOrFail($draft->id);
            $this->guard($draft, $from, 'Status draf sudah berubah. Muat ulang halaman.');

            // Riset tren bahan baca tim, bukan konten untuk diunggah: berhenti di Disetujui.
            if ($action === 'post' && $draft->type === 'trend_report') {
                throw ValidationException::withMessages(['status' => 'Riset tren tidak diposting. Cukup disetujui.']);
            }

            $fields = ['status' => $to];
            if ($action === 'submit') {
                $fields += ['submitted_at' => now(), 'reviewed_by_user_id' => null, 'reviewed_at' => null, 'review_note' => null];
            }
            if ($action === 'post') {
                $fields += ['posted_at' => now()];
            }

            $draft->forceFill($fields)->save();
            AuditLog::record("marketing_draft.{$action}", $draft, ['from' => $from, 'to' => $to], $actor->id);

            // Pemilik diberi tahu ada draf yang menunggu keputusannya (D-062).
            if ($action === 'submit') {
                $this->notifier->toRoles(['owner'], new StaffEvent('draft_submitted', 'draft', $draft->title, 'Draf konten menunggu keputusan Anda', $draft->title), $actor);
            }

            return $draft->refresh();
        });
    }

    /** Keputusan Pemilik atau Super Admin (`marketing_drafts.approve`). */
    public function decide(MarketingDraft $draft, bool $approve, ?string $note, User $actor): MarketingDraft
    {
        return DB::transaction(function () use ($draft, $approve, $note, $actor) {
            $draft = MarketingDraft::lockForUpdate()->findOrFail($draft->id);
            $this->guard($draft, [MarketingDraft::IN_REVIEW], 'Draf ini tidak sedang menunggu keputusan. Muat ulang halaman.');

            $status = $approve ? MarketingDraft::APPROVED : MarketingDraft::REJECTED;
            $draft->forceFill(['status' => $status, 'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
            AuditLog::record($approve ? 'marketing_draft.approved' : 'marketing_draft.rejected', $draft, ['note' => $note], $actor->id);

            // Marketing diberi tahu hasilnya; alasan penolakan ikut tampil sebagai kutipan.
            $this->notifier->toRoles(['marketing'], new StaffEvent('draft_decided', 'draft', $draft->title, ($approve ? 'Draf disetujui: ' : 'Draf ditolak: ').$draft->title, (string) $note), $actor);

            return $draft->refresh();
        });
    }

    public function delete(MarketingDraft $draft, User $actor): void
    {
        DB::transaction(function () use ($draft, $actor) {
            $draft = MarketingDraft::lockForUpdate()->findOrFail($draft->id);
            $this->guard($draft, MarketingDraft::EDITABLE, 'Hanya draf yang belum diajukan atau yang ditolak bisa dihapus.');

            AuditLog::record('marketing_draft.deleted', $draft, ['title' => $draft->title], $actor->id);
            $draft->delete();
        });
    }

    /** Target frekuensi mingguan (D-062), diatur Super Admin di Pengaturan. */
    public static function frequency(): array
    {
        $read = fn (string $key, int $default) => (int) (Setting::where('key', $key)->value('value') ?? $default);

        return ['videos_per_week' => $read('marketing.videos_per_week', 2)];
    }

    private function queueStoryboard(MarketingDraft $draft): void
    {
        if ($draft->type !== 'video_script' || ! MarketingGenerator::enabled()) {
            return;
        }

        // Mode AI penuh: AI belum dipanggil sampai foto acuan produk asli cukup.
        if ($draft->visual_mode === 'ai' && $draft->references()->count() < MarketingDraft::MIN_AI_REFERENCES) {
            $draft->forceFill(['storyboard_status' => MarketingDraft::STORYBOARD_WAITING, 'storyboard_error' => null])->saveQuietly();

            return;
        }

        $draft->forceFill(['storyboard_status' => MarketingDraft::STORYBOARD_PENDING, 'storyboard_error' => null])->saveQuietly();
        GenerateStoryboard::dispatch($draft->id)->afterCommit();
    }

    /** Draf yang ditolak kembali menjadi draf setelah disunting; catatan keputusan lama dibuang. */
    private function reopenIfRejected(MarketingDraft $draft): void
    {
        if ($draft->status === MarketingDraft::REJECTED) {
            $draft->forceFill(['status' => MarketingDraft::DRAFT, 'reviewed_by_user_id' => null, 'reviewed_at' => null, 'review_note' => null])->save();
        }
    }

    private function lockVideoDraft(MarketingDraft $draft): MarketingDraft
    {
        $draft = MarketingDraft::lockForUpdate()->findOrFail($draft->id);
        $this->guard($draft, MarketingDraft::EDITABLE, 'Draf yang sedang ditinjau atau sudah disetujui tidak bisa diubah. Tarik kembali dulu.');
        if ($draft->type !== 'video_script') {
            throw ValidationException::withMessages(['type' => 'Foto acuan dan catatan untuk AI hanya untuk draf skrip video.']);
        }
        $this->reopenIfRejected($draft);

        return $draft;
    }

    /** @param list<string> $allowed */
    private function guard(MarketingDraft $draft, array $allowed, string $message): void
    {
        if (! in_array($draft->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }
}
