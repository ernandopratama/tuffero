<?php

namespace Database\Seeders\Concerns;

use App\Services\ImageService;
use Illuminate\Support\Facades\Storage;

/**
 * Seeder data CONTOH (pelanggan uji, ulasan, promo, berita): hanya untuk lokal dan dev. Di production, data seperti ini
 * tampil ke pelanggan sungguhan atau mencemari laporan, jadi ditolak. Data asli dibuat dari dashboard.
 */
trait SampleData
{
    private function refuseInProduction(): bool
    {
        if (app()->isProduction()) {
            $this->command?->warn(class_basename($this).' dilewati: data contoh hanya untuk lokal dan dev, bukan production.');

            return true;
        }

        return false;
    }

    /**
     * Gambar contoh: salinan satu foto acak dari public/images/Produk (sudah WebP) ke disk public, dengan nama acak seperti
     * unggahan admin. Tidak lewat ImageService karena itu hanya menerima JPG/PNG. Null bila folder foto kosong.
     */
    private function sampleImage(string $area): ?string
    {
        $files = glob(public_path('images/Produk/*.webp')) ?: [];

        if (! $files) {
            return null;
        }

        $path = ImageService::randomPath($area, 'webp');
        Storage::disk('public')->put($path, (string) file_get_contents($files[array_rand($files)]));

        return $path;
    }
}
