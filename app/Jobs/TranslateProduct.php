<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Translation\ProductTranslationService;
use App\Services\Translation\TranslationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class TranslateProduct implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public int $productId) {}

    public function handle(ProductTranslationService $translations): void
    {
        $product = Product::find($this->productId);
        if (! $product) {
            return;
        }

        try {
            $translations->apply($product);
        } catch (TranslationException $e) {
            // Toko jatuh kembali ke bahasa Indonesia; log tanpa data pelanggan.
            Log::warning('Terjemahan produk gagal', ['product_id' => $product->id, 'reason' => $e->getMessage()]);
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);
        }
    }
}
