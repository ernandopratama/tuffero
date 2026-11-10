<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Foto di halaman Galeri toko (D-083). Dikelola Marketing dan Super Admin. */
#[Fillable(['title', 'title_en', 'category', 'is_featured', 'sort_order', 'is_published'])]
class GalleryPhoto extends Model
{
    use SoftDeletes;

    /** Kategori = tab ikon di halaman Galeri; kunci sama dengan `store.pages.gallery.cats`. */
    public const CATEGORIES = ['nastar' => 'Nastar', 'macaron' => 'Macaron', 'pia' => 'Pia', 'snack' => 'Camilan'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_featured' => 'boolean', 'is_published' => 'boolean', 'sort_order' => 'integer'];
    }

    /** Foto yang tampil di toko, urut menurut angka urutan. @return Collection<int, static> */
    public static function liveList(): Collection
    {
        return static::query()->where('is_published', true)->orderBy('sort_order')->orderBy('id')->get();
    }

    public function imageUrl(): string
    {
        return self::urlFor($this->image_path);
    }

    /** Foto besar untuk lightbox; bila tidak ada, foto yang sama. */
    public function fullUrl(): string
    {
        return self::urlFor($this->full_path ?: $this->image_path);
    }

    /** Teks tampil menurut bahasa halaman; Inggris jatuh ke Indonesia bila belum diisi. */
    public function titleFor(string $locale): string
    {
        return ($locale === 'en' && $this->title_en) ? $this->title_en : $this->title;
    }

    // `images/...` berkas statis bawaan; selainnya unggahan di storage.
    private static function urlFor(string $path): string
    {
        return str_starts_with($path, 'images/') ? "/{$path}" : "/storage/{$path}";
    }
}
