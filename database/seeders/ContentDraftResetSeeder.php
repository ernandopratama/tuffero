<?php

namespace Database\Seeders;

use App\Models\MarketingDraft;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Mengosongkan SELURUH Draf Konten (termasuk yang sudah dihapus ringan, catatan, dan foto acuan) lalu menjalankan
 * ContentDraftSeeder dari awal, supaya nomor Draf kembali #1 dan semua draf berstatus draf lagi. Untuk uji coba alur di lokal dan
 * dev setelah draf contoh habis diajukan. Jalankan: php artisan db:seed --class=ContentDraftResetSeeder.
 *
 * Penghapusan permanen dan tidak bisa dibatalkan; draf yang Anda buat sendiri ikut terhapus. Ditolak di production.
 */
class ContentDraftResetSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('ContentDraftResetSeeder dilewati: hanya untuk lokal dan dev, bukan production.');

            return;
        }

        $removed = 0;
        foreach (MarketingDraft::withTrashed()->get() as $draft) {
            Storage::disk('local')->deleteDirectory("marketing-references/{$draft->id}");
            // Catatan dan foto acuan ikut terhapus lewat kunci asing cascade.
            $draft->forceDelete();
            $removed++;
        }

        $this->command?->info("{$removed} draf dihapus permanen.");
        $this->call(ContentDraftSeeder::class);
    }
}
