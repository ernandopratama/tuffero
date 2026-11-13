<?php

namespace App\Services;

use App\Models\MarketingDraft;
use App\Notifications\StaffEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Pengingat tim marketing (D-062, D-079). Satu sumber hitungan untuk tiga pemakai: peringatan di Ringkasan dashboard,
 * notifikasi harian di lonceng, dan ringkasan hanya-baca untuk Hermes (pesan Telegram). Hanya data draf; tidak ada data
 * pelanggan, pesanan, atau harga. Hari dihitung menurut jam toko (Asia/Jakarta), bukan UTC.
 */
class MarketingReminderService
{
    public const STORE_TZ = 'Asia/Jakarta';

    /** Draf yang diajukan lebih lama dari ini dianggap menunggu terlalu lama dan diingatkan ke Pemilik. */
    public const STALE_DAYS = 2;

    public function __construct(private StaffNotifier $notifier) {}

    /** @return array{in_review: int, stale: int, rejected: int, due: int} */
    public function counts(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return [
            'in_review' => MarketingDraft::where('status', MarketingDraft::IN_REVIEW)->count(),
            'stale' => MarketingDraft::where('status', MarketingDraft::IN_REVIEW)->where('submitted_at', '<=', $now->subDays(self::STALE_DAYS))->count(),
            'rejected' => MarketingDraft::where('status', MarketingDraft::REJECTED)->count(),
            'due' => $this->dueQuery($now)->count(),
        ];
    }

    /**
     * Ringkasan untuk Hermes: hitungan dan judul draf yang jadwalnya besok atau sudah lewat. Tanpa isi draf.
     *
     * @return array<string, mixed>
     */
    public function summary(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $today = $now->setTimezone(self::STORE_TZ)->toDateString();

        return [
            ...$this->counts($now),
            'today' => $today,
            'due_items' => $this->dueQuery($now)->orderBy('scheduled_on')->limit(10)->get(['title', 'type', 'platform', 'scheduled_on'])->map(fn (MarketingDraft $d) => [
                'title' => $d->title,
                'type' => $d->type,
                'platform' => $d->platform,
                'scheduled_on' => $d->scheduled_on?->toDateString(),
                'overdue' => $d->scheduled_on?->toDateString() < $today,
            ])->all(),
            'review_items' => MarketingDraft::where('status', MarketingDraft::IN_REVIEW)->orderBy('submitted_at')->limit(10)->get(['title', 'type'])->map(fn (MarketingDraft $d) => ['title' => $d->title, 'type' => $d->type])->all(),
        ];
    }

    /**
     * Notifikasi harian di lonceng: Marketing bila ada draf disetujui yang jadwalnya besok atau lewat, Pemilik bila ada
     * draf yang menunggu keputusan terlalu lama. Sekali per hari walau perintahnya terpanggil dua kali.
     *
     * @return int jumlah jenis pengingat yang dikirim
     */
    public function remind(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $day = 'marketing-remind:'.$now->setTimezone(self::STORE_TZ)->toDateString();
        if (! Cache::add($day, 1, $now->addDay())) {
            return 0;
        }

        $counts = $this->counts($now);
        $sent = 0;

        if ($counts['due'] > 0) {
            $this->notifier->toRoles(['marketing'], new StaffEvent('draft_due', 'draft', '', $counts['due'].' draf siap diposting, jadwalnya besok atau sudah lewat', 'Buka Draf Konten lalu tandai sudah diposting setelah posting manual.'));
            $sent++;
        }
        if ($counts['stale'] > 0) {
            $this->notifier->toRoles(['owner'], new StaffEvent('draft_waiting', 'draft', '', $counts['stale'].' draf konten menunggu keputusan lebih dari '.self::STALE_DAYS.' hari', 'Buka Draf Konten untuk menyetujui atau menolak.'));
            $sent++;
        }

        return $sent;
    }

    /** Draf disetujui, belum diposting, jadwalnya paling lambat besok (termasuk yang sudah lewat). */
    private function dueQuery(CarbonImmutable $now)
    {
        $tomorrow = $now->setTimezone(self::STORE_TZ)->addDay()->toDateString();

        return MarketingDraft::where('status', MarketingDraft::APPROVED)->whereNotNull('scheduled_on')->whereDate('scheduled_on', '<=', $tomorrow);
    }
}
