<?php

namespace App\Services\Translation;

use App\Jobs\TranslateProduct;
use App\Models\AuditLog;
use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Terjemahan Inggris produk (D-065). Mode 'ai': dibuat otomatis saat disimpan lewat antrean.
 * Mode 'manual': hanya admin yang mengisi; AI hanya memberi usulan lewat suggest().
 */
class ProductTranslationService
{
    public function __construct(private Translator $translator) {}

    /** Sidik teks Indonesia sumber; berubah bila nama, deskripsi, atau nama varian berubah. */
    public function sourceHash(Product $product): string
    {
        $product->loadMissing('variants');

        return sha1((string) json_encode([
            $product->name,
            $product->description,
            $product->variants->sortBy('id')->map(fn ($variant) => [$variant->id, $variant->name])->values(),
        ]));
    }

    public function isOutdated(Product $product): bool
    {
        return $product->translation_hash !== null && $product->translation_hash !== $this->sourceHash($product);
    }

    /**
     * Usulan terjemahan tanpa menyimpan apa pun.
     *
     * @return array{name_en: string, description_en: string|null, variants: array<int, string>}
     *
     * @throws TranslationException
     */
    public function suggest(Product $product): array
    {
        $product->loadMissing('variants');

        $texts = ['name' => $product->name];
        if (filled($product->description)) {
            $texts['description'] = $product->description;
        }
        foreach ($product->variants as $variant) {
            $texts["variant.{$variant->id}"] = $variant->name;
        }

        $out = $this->translator->translate($texts);

        return [
            'name_en' => Str::limit($out['name'], 150, ''),
            'description_en' => isset($out['description']) ? Str::limit($out['description'], 5000, '') : null,
            'variants' => $product->variants->mapWithKeys(fn ($variant) => [$variant->id => Str::limit($out["variant.{$variant->id}"], 100, '')])->all(),
        ];
    }

    /** Terjemahkan dan simpan, hanya untuk mode 'ai' yang belum diterjemahkan atau sumbernya berubah. */
    public function apply(Product $product): void
    {
        if ($product->translation_mode !== 'ai') {
            return;
        }
        $hash = $this->sourceHash($product);
        if ($product->name_en !== null && $product->translation_hash === $hash) {
            return;
        }

        $suggestion = $this->suggest($product);

        $product->forceFill([
            'name_en' => $suggestion['name_en'],
            'description_en' => $suggestion['description_en'],
            'translation_hash' => $hash,
        ])->save();
        foreach ($product->variants as $variant) {
            $variant->forceFill(['name_en' => $suggestion['variants'][$variant->id] ?? null])->save();
        }

        AuditLog::record('product.translated', $product, ['mode' => 'ai'], null);
    }

    /** Antre terjemahan otomatis bila produk bermode 'ai' dan kunci Claude ada. Simpan produk tidak pernah gagal karenanya. */
    public function queue(Product $product): void
    {
        if ($product->translation_mode === 'ai' && filled(config('services.anthropic.key')) && ($product->name_en === null || $product->translation_hash !== $this->sourceHash($product))) {
            TranslateProduct::dispatch($product->id)->afterCommit();
        }
    }

    /** Tandai terjemahan manual sudah mengikuti teks Indonesia saat ini. */
    public function markSynced(Product $product): void
    {
        $product->forceFill(['translation_hash' => $this->sourceHash($product)])->save();
    }
}
