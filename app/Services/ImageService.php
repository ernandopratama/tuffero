<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Semua gambar unggahan admin (D-086): hanya JPG, JPEG, dan PNG yang diterima, dan semuanya disimpan ulang sebagai WebP.
 * Menyimpan ulang membuang metadata (EXIF, lokasi GPS), memperkecil ukuran, dan membatasi sisi terpanjang.
 */
class ImageService
{
    /** Aturan validasi bersama; dipakai semua endpoint unggah gambar. */
    public const MIMES = 'mimes:jpg,jpeg,png';

    public const MIME_MESSAGE = 'Gambar harus JPG, JPEG, atau PNG.';

    private const ALLOWED_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG];

    /**
     * Simpan sebagai WebP di disk publik dan kembalikan path relatifnya.
     *
     * @param  string  $directory  Folder di disk publik, misalnya "products/12"
     * @param  int  $maxSide  Sisi terpanjang maksimum (piksel); gambar lebih kecil tidak diperbesar
     */
    public function storeWebp(UploadedFile $file, string $directory, int $maxSide = 1600, int $quality = 82, bool $square = false): string
    {
        $path = trim($directory, '/').'/'.Str::random(32).'.webp';
        Storage::disk('public')->put($path, $this->toWebp($file, $maxSide, $quality, $square));

        return $path;
    }

    /** Isi berkas WebP hasil konversi. Isi berkas diperiksa sendiri; ekstensi dan MIME dari klien tidak dipercaya. */
    public function toWebp(UploadedFile $file, int $maxSide = 1600, int $quality = 82, bool $square = false): string
    {
        $real = $file->getRealPath();
        $info = $real ? @getimagesize($real) : false;

        if (! $info || ! in_array($info[2], self::ALLOWED_TYPES, true)) {
            throw ValidationException::withMessages(['image' => self::MIME_MESSAGE]);
        }

        // Gambar besar butuh memori sekitar lebar x tinggi x 5 byte; naikkan batas sementara bila perlu.
        $need = (int) ($info[0] * $info[1] * 5 / 1048576) + 64;
        if ($need > 128 && $this->memoryLimitMb() < $need) {
            @ini_set('memory_limit', $need.'M');
        }

        $source = @imagecreatefromstring((string) file_get_contents($real));
        if ($source === false) {
            throw ValidationException::withMessages(['image' => 'Berkas bukan gambar yang valid.']);
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            $source = $this->orient($source, $real);
        }

        [$w, $h] = [imagesx($source), imagesy($source)];

        // Foto profil: potong bagian tengah menjadi persegi supaya wajah tidak gepeng di lingkaran.
        if ($square && $w !== $h) {
            $side = min($w, $h);
            $cropped = imagecrop($source, ['x' => intdiv($w - $side, 2), 'y' => intdiv($h - $side, 2), 'width' => $side, 'height' => $side]);
            if ($cropped !== false) {
                $source = $cropped;
                [$w, $h] = [$side, $side];
            }
        }

        $scale = min(1, $maxSide / max($w, $h));
        $canvas = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));

        // Transparansi PNG dipertahankan.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas), $w, $h);

        ob_start();
        imagewebp($canvas, null, $quality);
        $webp = (string) ob_get_clean();

        if ($webp === '') {
            throw ValidationException::withMessages(['image' => 'Gambar tidak bisa diproses.']);
        }

        return $webp;
    }

    /** Foto dari ponsel menyimpan arah putar di EXIF; tanpa ini hasil WebP bisa miring karena EXIF dibuang. */
    private function orient(\GdImage $image, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated ?: $image;
    }

    private function memoryLimitMb(): int
    {
        $limit = ini_get('memory_limit');

        return $limit === '-1' ? PHP_INT_MAX : (int) (preg_match('/^(\d+)([KMG]?)$/i', $limit, $m) ? $m[1] * match (strtoupper($m[2])) {
            'G' => 1024, 'K' => 1 / 1024, default => 1,
        } : 128);
    }
}
