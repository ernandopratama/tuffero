<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\NewsItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Popup berita di beranda toko (D-085): kampanye berjalan (otomatis) ditambah berita, update, dan promo manual.
 * Sekali tampil memuat paling banyak LIMIT item.
 */
class NewsService
{
    public const LIMIT = 5;

    public function __construct(private ImageService $images) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): NewsItem
    {
        return DB::transaction(function () use ($data, $actor) {
            $item = NewsItem::create($data);

            AuditLog::record('news.created', $item, ['title' => $item->title, 'kind' => $item->kind], $actor->id);

            return $item->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(NewsItem $item, array $data, User $actor): NewsItem
    {
        return DB::transaction(function () use ($item, $data, $actor) {
            AuditLog::saveWithAudit($item, $data, 'news.updated', $actor);

            return $item->refresh();
        });
    }

    public function setImage(NewsItem $item, UploadedFile $file, User $actor): NewsItem
    {
        $old = $item->image_path;
        $path = $this->images->storeWebp($file, "news/{$item->id}", 1280);

        DB::transaction(function () use ($item, $path, $actor) {
            $item->forceFill(['image_path' => $path])->save();
            AuditLog::record('news.image_changed', $item, ['image_path' => $path], $actor->id);
        });

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return $item->refresh();
    }

    public function removeImage(NewsItem $item, User $actor): NewsItem
    {
        $old = $item->image_path;

        DB::transaction(function () use ($item, $actor) {
            $item->forceFill(['image_path' => null])->save();
            AuditLog::record('news.image_removed', $item, [], $actor->id);
        });

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return $item->refresh();
    }

    public function delete(NewsItem $item, User $actor): void
    {
        DB::transaction(function () use ($item, $actor) {
            AuditLog::record('news.deleted', $item, ['title' => $item->title], $actor->id);
            $item->delete();
        });
    }

    /**
     * Isi popup untuk satu bahasa. Urutan: kampanye berjalan (yang paling cepat berakhir dulu), berita manual menurut urutan,
     * lalu kampanye yang akan datang. Dipotong sampai LIMIT. `version` berubah bila isinya berubah, dipakai browser
     * untuk memutuskan perlu tampil lagi dalam satu sesi.
     *
     * @return array{items: list<array<string, mixed>>, version: string}
     */
    public function popup(string $locale): array
    {
        $words = trans('store.news', [], $locale);
        $campaigns = Campaign::visible()->orderBy('starts_at')->limit(self::LIMIT)->get();
        $live = $campaigns->filter(fn (Campaign $c) => $c->starts_at->lte(now()))->sortBy('ends_at');
        $upcoming = $campaigns->filter(fn (Campaign $c) => $c->starts_at->gt(now()));

        $manual = NewsItem::live()->orderBy('sort_order')->orderByDesc('id')->limit(self::LIMIT)->get();

        $items = collect()
            ->concat($live->map(fn (Campaign $c) => $this->fromCampaign($c, true, $locale, $words)))
            ->concat($manual->map(fn (NewsItem $n) => $this->fromNews($n, $locale, $words)))
            ->concat($upcoming->map(fn (Campaign $c) => $this->fromCampaign($c, false, $locale, $words)))
            ->take(self::LIMIT)
            ->values();

        $version = md5($items->pluck('key')->implode('|').'|'.$items->pluck('stamp')->implode('|'));

        return ['items' => $items->map(fn (array $item) => \Illuminate\Support\Arr::except($item, ['stamp']))->all(), 'version' => $version];
    }

    /** @param array<string, string> $words @return array<string, mixed> */
    private function fromNews(NewsItem $n, string $locale, array $words): array
    {
        $hasLink = $n->cta_url && $n->textFor('cta_label', $locale);

        return [
            'key' => 'n'.$n->id,
            'stamp' => $n->updated_at?->timestamp,
            'badge' => $words['kinds'][$n->kind] ?? $words['kinds']['news'],
            'kind' => $n->kind,
            'title' => $n->textFor('title', $locale),
            'body' => $n->textFor('body', $locale),
            'image' => $n->imageUrl(),
            'note' => $n->ends_at ? $words['until'].' '.$this->day($n->ends_at, $locale) : null,
            'cta' => $hasLink ? ['label' => $n->textFor('cta_label', $locale), 'url' => $n->cta_url] : null,
        ];
    }

    /** @param array<string, string> $words @return array<string, mixed> */
    private function fromCampaign(Campaign $c, bool $live, string $locale, array $words): array
    {
        return [
            'key' => 'c'.$c->id,
            'stamp' => $c->updated_at?->timestamp,
            'badge' => $c->is_flash ? $words['flash'] : $words['campaign'],
            'kind' => $c->is_flash ? 'flash' : 'campaign',
            'title' => $c->title,
            'body' => $c->description ? mb_strimwidth($c->description, 0, 160, '…') : $words[$live ? 'campaignLive' : 'campaignSoon'],
            'image' => $c->image_path ? '/storage/'.$c->image_path : null,
            'note' => $live ? $words['until'].' '.$this->day($c->ends_at, $locale) : $words['from'].' '.$this->day($c->starts_at, $locale),
            'cta' => ['label' => $words['seePromo'], 'url' => '/promo/'.$c->slug],
        ];
    }

    private function day(\DateTimeInterface $date, string $locale): string
    {
        return \Illuminate\Support\Carbon::instance($date)->locale($locale)->translatedFormat('j M Y');
    }

    /** Jumlah item yang akan tampil sekarang; untuk indikator slot di admin. */
    public function slotsUsed(string $locale = 'id'): int
    {
        return count($this->popup($locale)['items']);
    }

    /** @return Collection<int, NewsItem> */
    public function manualList(): Collection
    {
        return NewsItem::query()->orderBy('sort_order')->orderByDesc('id')->limit(200)->get();
    }
}
