<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MarketingDraft;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\StaffEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alur draf konten: draft → in_review → approved atau rejected → posted. Tidak ada publikasi otomatis (BR-19).
 * Setiap perpindahan memeriksa status terkini dengan kunci baris, supaya dua orang yang menekan tombol bersamaan tidak saling menimpa.
 */
class MarketingDraftService
{
    public function __construct(private StaffNotifier $notifier) {}

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
            $draft->forceFill(['status' => MarketingDraft::DRAFT, 'origin' => $origin, 'external_id' => $externalId, 'created_by_user_id' => $actor?->id])->save();

            AuditLog::record('marketing_draft.created', $draft, ['title' => $draft->title, 'type' => $draft->type, 'origin' => $origin], $actor?->id);

            return $draft->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(MarketingDraft $draft, array $data, User $actor): MarketingDraft
    {
        return DB::transaction(function () use ($draft, $data, $actor) {
            $draft = MarketingDraft::lockForUpdate()->findOrFail($draft->id);
            $this->guard($draft, MarketingDraft::EDITABLE, 'Draf yang sedang ditinjau atau sudah disetujui tidak bisa diubah. Tarik kembali dulu.');

            // Draf yang ditolak kembali menjadi draf setelah disunting; catatan keputusan lama dibuang.
            if ($draft->status === MarketingDraft::REJECTED) {
                $draft->forceFill(['status' => MarketingDraft::DRAFT, 'reviewed_by_user_id' => null, 'reviewed_at' => null, 'review_note' => null]);
            }

            AuditLog::saveWithAudit($draft, $data, 'marketing_draft.updated', $actor);

            return $draft->refresh();
        });
    }

    public function move(MarketingDraft $draft, string $action, User $actor): MarketingDraft
    {
        [$from, $to] = self::MOVES[$action];

        return DB::transaction(function () use ($draft, $action, $from, $to, $actor) {
            $draft = MarketingDraft::lockForUpdate()->findOrFail($draft->id);
            $this->guard($draft, $from, 'Status draf sudah berubah. Muat ulang halaman.');

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

        return ['posts_per_week' => $read('marketing.posts_per_week', 3), 'videos_per_week' => $read('marketing.videos_per_week', 2)];
    }

    /** @param list<string> $allowed */
    private function guard(MarketingDraft $draft, array $allowed, string $message): void
    {
        if (! in_array($draft->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }
}
