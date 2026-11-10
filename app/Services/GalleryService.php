<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\GalleryPhoto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Foto galeri toko (D-083): simpan, ubah, ganti foto, hapus lunak. Foto unggahan diubah ulang ke WebP (metadata EXIF hilang, ukuran hemat). */
class GalleryService
{
    private const MAX_SIDE = 1600;

    public function __construct(private ImageService $images) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, UploadedFile $file, User $actor): GalleryPhoto
    {
        $path = $this->storeImage($file);

        try {
            return DB::transaction(function () use ($data, $path, $actor) {
                $photo = new GalleryPhoto($data);
                $photo->forceFill(['image_path' => $path])->save();

                AuditLog::record('gallery.created', $photo, ['title' => $photo->title, 'category' => $photo->category], $actor->id);

                return $photo->refresh();
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);
            throw $e;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(GalleryPhoto $photo, array $data, User $actor): GalleryPhoto
    {
        return DB::transaction(function () use ($photo, $data, $actor) {
            AuditLog::saveWithAudit($photo, $data, 'gallery.updated', $actor);

            return $photo->refresh();
        });
    }

    public function setImage(GalleryPhoto $photo, UploadedFile $file, User $actor): GalleryPhoto
    {
        $old = $photo->image_path;
        $path = $this->storeImage($file);

        DB::transaction(function () use ($photo, $path, $actor) {
            // Foto besar bawaan dilepas: foto baru dipakai juga untuk lightbox.
            $photo->forceFill(['image_path' => $path, 'full_path' => null])->save();

            AuditLog::record('gallery.image_changed', $photo, ['image_path' => $path], $actor->id);
        });

        $this->deleteUpload($old);

        return $photo;
    }

    /** Hapus lunak: baris dan berkas tetap ada; foto hilang dari daftar dan toko. */
    public function delete(GalleryPhoto $photo, User $actor): void
    {
        DB::transaction(function () use ($photo, $actor) {
            AuditLog::record('gallery.deleted', $photo, ['title' => $photo->title], $actor->id);
            $photo->delete();
        });
    }

    /** Hanya berkas unggahan yang dihapus; berkas statis bawaan (`images/...`) tidak pernah disentuh. */
    private function deleteUpload(string $path): void
    {
        if (! str_starts_with($path, 'images/')) {
            Storage::disk('public')->delete($path);
        }
    }

    private function storeImage(UploadedFile $file): string
    {
        return $this->images->storeWebp($file, 'gallery', self::MAX_SIDE);
    }
}
