<?php

namespace App\Services;

use App\Models\InventoryStock;
use App\Models\ProductVariant;
use Illuminate\Contracts\Session\Session;

/**
 * Keranjang tamu di session: hanya SKU dan jumlah. Harga dan stok selalu dibaca ulang dari database (BR-08).
 */
class CartService
{
    /** Batas keras permintaan; batas pesanan sebenarnya per varian (product_variants.max_order_quantity, default 50). */
    public const MAX_QUANTITY = 999;

    private const KEY = 'cart.items';

    public function __construct(private Session $session) {}

    public function set(string $sku, int $quantity): void
    {
        $this->session->put(self::KEY.'.'.$this->key($sku), ['sku' => $sku, 'quantity' => min($quantity, $this->limitFor($sku))]);
    }

    public function add(string $sku, int $quantity): void
    {
        $this->set($sku, $this->quantity($sku) + $quantity);
    }

    public function remove(string $sku): void
    {
        $this->session->forget(self::KEY.'.'.$this->key($sku));
    }

    public function clear(): void
    {
        $this->session->forget(self::KEY);
    }

    /**
     * Jumlah di lencana header: sama dengan count di API, jadi SKU yang sudah tidak ada ikut terbuang.
     */
    public function count(): int
    {
        return array_sum(array_column($this->summary()['items'], 'quantity'));
    }

    /**
     * Isi keranjang dengan data terkini. SKU yang sudah tidak ada dibuang; yang ditarik dari toko diberi tanda.
     *
     * @return array{items: list<array<string, mixed>>, subtotal: int, weight_gram: int, can_checkout: bool}
     */
    public function summary(): array
    {
        $entries = collect($this->session->get(self::KEY, []));
        $variants = ProductVariant::with(['product' => fn ($query) => $query->with('images')])
            ->whereIn('sku', $entries->pluck('sku'))
            ->whereHas('product')
            ->get()
            ->keyBy('sku');

        $available = InventoryStock::availableByVariant($variants->pluck('id'));

        $items = $entries->values()->map(function (array $entry) use ($variants, $available) {
            /** @var ProductVariant|null $variant */
            $variant = $variants->get($entry['sku']);

            if (! $variant) {
                return null;
            }

            $sellable = $variant->is_active && $variant->product->is_published;
            $stock = $available[$variant->id] ?? 0;

            return [
                'sku' => $variant->sku,
                'variant_id' => $variant->id,
                'product_name' => $variant->product->name,
                'variant_name' => $variant->name,
                'photo' => $variant->product->images->first()?->path,
                'price' => $variant->price,
                'compare_at_price' => $variant->compare_at_price,
                'weight_gram' => $variant->weight_gram,
                'quantity' => min($entry['quantity'], $variant->max_order_quantity),
                'max_quantity' => $variant->max_order_quantity,
                'line_total' => $variant->price * min($entry['quantity'], $variant->max_order_quantity),
                'issue' => ! $sellable ? 'unavailable' : ($stock < min($entry['quantity'], $variant->max_order_quantity) ? 'insufficient_stock' : null),
                'available' => $sellable ? $stock : 0,
            ];
        })->filter()->values();

        return [
            'items' => $items->all(),
            'subtotal' => (int) $items->sum('line_total'),
            'weight_gram' => (int) $items->sum(fn (array $item) => $item['weight_gram'] * $item['quantity']),
            'can_checkout' => $items->isNotEmpty() && $items->every(fn (array $item) => $item['issue'] === null),
        ];
    }

    private function limitFor(string $sku): int
    {
        return (int) (ProductVariant::where('sku', $sku)->value('max_order_quantity') ?? self::MAX_QUANTITY);
    }

    private function quantity(string $sku): int
    {
        return (int) $this->session->get(self::KEY.'.'.$this->key($sku).'.quantity', 0);
    }

    // Titik pada kunci session berarti susunan bertingkat, jadi SKU tidak dipakai mentah.
    private function key(string $sku): string
    {
        return md5($sku);
    }
}
