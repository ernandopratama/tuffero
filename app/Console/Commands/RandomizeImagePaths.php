<?php

namespace App\Console\Commands;

use App\Services\ImageService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Memindahkan gambar lama dari path ber-ID ("products/10/Dubai 1.webp") ke path acak (ImageService::randomPath).
 *
 * Tanpa opsi: dry-run, hanya mencetak rencana. --apply menyalin berkas, memverifikasi isinya, lalu memperbarui kolom DB;
 * berkas lama TIDAK dihapus. --cleanup=<manifest> menghapus berkas lama setelah diverifikasi. --rollback=<manifest>
 * mengembalikan kolom DB ke path lama dan menghapus salinan baru (hanya bila berkas lama masih ada).
 * Aman diulang: path yang sudah acak dan path statis (images/...) dilewati.
 */
#[Signature('images:randomize-paths {--apply : Salin berkas dan perbarui DB} {--cleanup= : Hapus berkas lama dari manifest ini} {--rollback= : Kembalikan path dari manifest ini} {--force : Lewati konfirmasi di production}')]
#[Description('Pindahkan gambar publik lama ke path acak tanpa ID database. Bawaan: dry-run.')]
class RandomizeImagePaths extends Command
{
    use ConfirmableTrait;

    /** Tabel, kolom, dan area folder baru. */
    private const COLUMNS = [
        ['product_images', 'path', 'products'],
        ['campaigns', 'image_path', 'campaigns'],
        ['news_items', 'image_path', 'news'],
        ['promo_contents', 'image_path', 'promo'],
        ['gallery_photos', 'image_path', 'gallery'],
        ['gallery_photos', 'full_path', 'gallery'],
        ['users', 'avatar_path', 'avatars'],
    ];

    private const MANIFEST_DIR = 'image-path-migrations';

    public function handle(): int
    {
        $modes = array_filter([$this->option('apply'), $this->option('cleanup'), $this->option('rollback')]);
        if (count($modes) > 1) {
            $this->error('Pilih satu: --apply, --cleanup, atau --rollback.');

            return self::FAILURE;
        }

        if ($this->option('cleanup') || $this->option('rollback')) {
            $manifest = $this->readManifest((string) ($this->option('cleanup') ?: $this->option('rollback')));
            if ($manifest === null || ! $this->confirmToProceed()) {
                return self::FAILURE;
            }

            return $this->option('cleanup') ? $this->cleanup($manifest) : $this->rollback($manifest);
        }

        $plan = $this->plan();
        $this->table(['Tabel', 'ID', 'Kolom', 'Path lama', 'Status'], array_map(fn (array $r) => [$r['table'], $r['id'], $r['column'], $r['old'], $r['status']], $plan));
        $todo = array_values(array_filter($plan, fn (array $r) => $r['status'] === 'dipindah'));
        $this->info(count($todo).' berkas akan dipindah, '.(count($plan) - count($todo)).' dilewati.');

        if (! $this->option('apply')) {
            $this->line('Dry-run: tidak ada yang diubah. Jalankan dengan --apply setelah backup storage/app/public dan DB.');

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        return $this->apply($todo);
    }

    /** @return list<array{table: string, column: string, area: string, id: int, old: string, status: string}> */
    private function plan(): array
    {
        $rows = [];
        foreach (self::COLUMNS as [$table, $column, $area]) {
            foreach (DB::table($table)->whereNotNull($column)->where($column, '!=', '')->orderBy('id')->get(['id', $column]) as $row) {
                $old = (string) $row->{$column};
                $rows[] = ['table' => $table, 'column' => $column, 'area' => $area, 'id' => (int) $row->id, 'old' => $old, 'status' => $this->status($old)];
            }
        }

        return $rows;
    }

    private function status(string $path): string
    {
        return match (true) {
            str_starts_with($path, 'images/') => 'statis, dilewati',
            (bool) preg_match(ImageService::RANDOM_PATH_PATTERN, $path) => 'sudah acak',
            str_contains($path, '..') || str_starts_with($path, '/') => 'path tidak aman, dilewati',
            $this->extension($path) === null => 'ekstensi tidak dikenal, dilewati',
            ! $this->disk()->exists($path) => 'berkas hilang, dilewati',
            default => 'dipindah',
        };
    }

    /** @param list<array{table: string, column: string, area: string, id: int, old: string, status: string}> $todo */
    private function apply(array $todo): int
    {
        $file = self::MANIFEST_DIR.'/'.now()->format('Ymd_His').'.json';
        $entries = [];
        $failed = 0;

        foreach ($todo as $row) {
            $extension = (string) $this->extension($row['old']);
            $prefix = str_contains($row['old'], '/auto-') ? 'auto-' : '';
            $new = ImageService::randomPath($row['area'], $extension, $prefix);

            if (! $this->disk()->copy($row['old'], $new) || $this->disk()->checksum($new) !== $this->disk()->checksum($row['old'])) {
                $this->disk()->delete($new);
                $this->warn("Gagal menyalin {$row['table']}#{$row['id']}: {$row['old']}");
                $failed++;

                continue;
            }

            // Hanya bila path belum diganti admin sejak rencana dibuat.
            $updated = DB::table($row['table'])->where('id', $row['id'])->where($row['column'], $row['old'])->update([$row['column'] => $new]);
            if ($updated !== 1) {
                $this->disk()->delete($new);
                $this->warn("Dilewati, path berubah: {$row['table']}#{$row['id']}");

                continue;
            }

            $entries[] = ['table' => $row['table'], 'column' => $row['column'], 'id' => $row['id'], 'old' => $row['old'], 'new' => $new];
            // Ditulis setiap baris supaya proses yang terhenti tetap bisa dibersihkan atau dikembalikan.
            Storage::disk('local')->put($file, (string) json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $this->info(count($entries).' dipindah, '.$failed.' gagal. Berkas lama belum dihapus.');
        if ($entries !== []) {
            $this->line("Manifest: storage/app/private/{$file}");
            $this->line("Periksa toko, lalu: php artisan images:randomize-paths --cleanup={$file}");
            $this->line("Untuk mengembalikan: php artisan images:randomize-paths --rollback={$file}");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param list<array{table: string, column: string, id: int, old: string, new: string}> $manifest */
    private function cleanup(array $manifest): int
    {
        $deleted = 0;
        foreach ($manifest as $entry) {
            $current = DB::table($entry['table'])->where('id', $entry['id'])->value($entry['column']);
            if ($current !== $entry['new'] || ! $this->disk()->exists($entry['new'])) {
                $this->warn("Tidak dihapus (DB atau berkas baru tidak cocok): {$entry['old']}");

                continue;
            }
            if ($this->disk()->exists($entry['old']) && $this->disk()->delete($entry['old'])) {
                $deleted++;
            }
        }
        $this->info("{$deleted} berkas lama dihapus. Rollback tidak bisa lagi untuk berkas ini.");

        return self::SUCCESS;
    }

    /** @param list<array{table: string, column: string, id: int, old: string, new: string}> $manifest */
    private function rollback(array $manifest): int
    {
        $restored = 0;
        foreach ($manifest as $entry) {
            if (! $this->disk()->exists($entry['old'])) {
                $this->warn("Tidak bisa dikembalikan, berkas lama sudah dihapus: {$entry['old']}");

                continue;
            }
            $updated = DB::table($entry['table'])->where('id', $entry['id'])->where($entry['column'], $entry['new'])->update([$entry['column'] => $entry['old']]);
            if ($updated === 1) {
                $this->disk()->delete($entry['new']);
                $restored++;
            } else {
                $this->warn("Dilewati, path sudah berubah: {$entry['table']}#{$entry['id']}");
            }
        }
        $this->info("{$restored} path dikembalikan.");

        return self::SUCCESS;
    }

    /** @return list<array{table: string, column: string, id: int, old: string, new: string}>|null */
    private function readManifest(string $file): ?array
    {
        // Hanya berkas di folder manifest; nama tanpa "../".
        if (! preg_match('#^'.self::MANIFEST_DIR.'/\d{8}_\d{6}\.json$#', $file) || ! Storage::disk('local')->exists($file)) {
            $this->error('Manifest tidak ditemukan. Contoh: '.self::MANIFEST_DIR.'/20261118_120000.json');

            return null;
        }

        $data = json_decode((string) Storage::disk('local')->get($file), true);
        if (! is_array($data)) {
            $this->error('Manifest rusak.');

            return null;
        }
        foreach ($data as $entry) {
            // Tabel dan kolom harus dari daftar di atas; manifest tidak boleh mengarahkan ke kolom lain.
            if (! is_array($entry) || ! in_array([$entry['table'] ?? null, $entry['column'] ?? null], array_map(fn (array $c) => [$c[0], $c[1]], self::COLUMNS), true) || ! isset($entry['id'], $entry['old'], $entry['new'])
                || ! preg_match(ImageService::RANDOM_PATH_PATTERN, (string) $entry['new']) || $this->status((string) $entry['old']) === 'path tidak aman, dilewati') {
                $this->error('Manifest memuat baris yang tidak dikenal.');

                return null;
            }
        }

        return $data;
    }

    private function extension(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['webp', 'jpg'], true) ? $extension : null;
    }

    private function disk(): Filesystem
    {
        return Storage::disk('public');
    }
}
