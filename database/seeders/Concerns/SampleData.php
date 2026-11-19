<?php

namespace Database\Seeders\Concerns;

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
}
