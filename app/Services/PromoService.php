<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\PromoContent;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PromoService
{
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
        // Konten baru selalu draf: banner belum punya gambar saat dibuat.
        unset($data['is_published']);

        return DB::transaction(function () use ($data, $actor) {
            $content = PromoContent::create($data)->refresh();

            AuditLog::record('content.created', $content, ['type' => $content->type, 'title' => $content->title], $actor->id);

            return $content;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateContent(PromoContent $content, array $data, User $actor): PromoContent
    {
        if (($data['is_published'] ?? false) && $content->type === 'banner' && ! $content->image_path) {
            throw ValidationException::withMessages(['is_published' => 'Unggah gambar banner sebelum menayangkan.']);
        }

        return DB::transaction(function () use ($content, $data, $actor) {
            AuditLog::saveWithAudit($content, $data, 'content.updated', $actor);

            return $content->refresh();
        });
    }

    public function setContentImage(PromoContent $content, UploadedFile $file, User $actor): PromoContent
    {
        $old = $content->image_path;
        $path = $file->store("promo/{$content->id}", 'public');

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
