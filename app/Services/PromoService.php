<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\PromoContent;
use App\Models\User;
use App\Models\Voucher;
use App\Support\VideoLink;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PromoService
{
    public function __construct(private VideoThumbnailService $thumbnails, private ImageService $images) {}

    /** @param array<string, mixed> $data */
    public function createVoucher(array $data, User $actor): Voucher
    {
        return DB::transaction(function () use ($data, $actor) {
            $voucher = Voucher::create($data)->refresh();

            AuditLog::record('voucher.created', $voucher, ['code' => $voucher->code, 'is_active' => $voucher->is_active], $actor->id);

            return $voucher;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateVoucher(Voucher $voucher, array $data, User $actor): Voucher
    {
        return DB::transaction(function () use ($voucher, $data, $actor) {
            AuditLog::saveWithAudit($voucher, $data, 'voucher.updated', $actor);

            return $voucher->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function createContent(array $data, User $actor): PromoContent
    {
        $content = $this->saveNewContent($data, $actor);

        // Gagal mengambil thumbnail tidak membatalkan simpan: admin bisa mengunggah manual.
        $content->thumbnailStatus = $this->thumbnails->fetch($content, $actor);

        return $content;
    }

    /** @param array<string, mixed> $data */
    private function saveNewContent(array $data, User $actor): PromoContent
    {
        // Konten baru selalu draf: banner dan video belum punya gambar saat dibuat.
        unset($data['is_published']);
        if (($data['type'] ?? null) === 'video') {
            $data += VideoLink::parse($data['video_url']);
        }

        return DB::transaction(function () use ($data, $actor) {
            $content = PromoContent::create($data)->refresh();

            AuditLog::record('content.created', $content, ['type' => $content->type, 'title' => $content->title], $actor->id);

            return $content;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateContent(PromoContent $content, array $data, User $actor): PromoContent
    {
        $urlChanged = $content->type === 'video' && isset($data['video_url']) && $data['video_url'] !== $content->video_url;
        $content = $this->saveContentChanges($content, $data, $actor);

        // Thumbnail unggahan admin tidak ditimpa diam-diam; hanya yang diambil sistem atau yang masih kosong.
        if ($urlChanged && (! $content->image_path || $this->thumbnails->isAuto($content))) {
            $content->thumbnailStatus = $this->thumbnails->fetch($content, $actor);
        }

        return $content;
    }

    /** @param array<string, mixed> $data */
    private function saveContentChanges(PromoContent $content, array $data, User $actor): PromoContent
    {
        if (($data['is_published'] ?? false) && ! $content->image_path && in_array($content->type, ['banner', 'video'], true)) {
            throw ValidationException::withMessages(['is_published' => $content->type === 'video' ? 'Unggah thumbnail video sebelum menayangkan.' : 'Unggah gambar banner sebelum menayangkan.']);
        }

        if ($content->type === 'video' && isset($data['video_url'])) {
            $data += VideoLink::parse($data['video_url']);
        }

        return DB::transaction(function () use ($content, $data, $actor) {
            AuditLog::saveWithAudit($content, $data, 'content.updated', $actor);

            return $content->refresh();
        });
    }

    /** Hapus lunak: baris dan berkas gambar tetap ada; konten hilang dari daftar dan toko. */
    public function deleteContent(PromoContent $content, User $actor): void
    {
        DB::transaction(function () use ($content, $actor) {
            AuditLog::record('content.deleted', $content, ['type' => $content->type, 'title' => $content->title], $actor->id);
            $content->delete();
        });
    }

    /** Ambil ulang thumbnail otomatis atas permintaan admin (menimpa thumbnail yang ada). */
    public function refreshThumbnail(PromoContent $content, User $actor): string
    {
        if (! $this->thumbnails->supports($content)) {
            throw ValidationException::withMessages(['video_url' => 'Thumbnail otomatis hanya untuk tautan video lengkap YouTube atau TikTok. Unggah manual.']);
        }

        $content->thumbnailStatus = $this->thumbnails->fetch($content, $actor);

        return $content->thumbnailStatus;
    }

    public function setContentImage(PromoContent $content, UploadedFile $file, User $actor): PromoContent
    {
        $old = $content->image_path;
        $path = $this->images->storeWebp($file, "promo/{$content->id}", 1920);

        DB::transaction(function () use ($content, $path, $actor) {
            $content->forceFill(['image_path' => $path])->save();

            AuditLog::record('content.image_changed', $content, ['image_path' => $path], $actor->id);
        });

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return $content;
    }
}
