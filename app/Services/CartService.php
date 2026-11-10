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

    // Item yang pembeli batalkan centangnya (kunci md5 SKU). Item baru otomatis terpilih; hanya item terpilih yang dihitung dan dibeli.
    private const UNSELECTED = 'cart.unselected';

    public function __construct(private Session $session, private DiscountService $discounts) {}

    public function set(string $sku, int $quantity): void
    {
        $this->session->put(self::KEY.'.'.$this->key($sku), ['sku' => $sku, 'quantity' => min($quantity, $this->limitFor($sku))]);
    }

    public function add(string $sku, int $quantity): void
    {
        // Menambah produk berarti ingin membelinya, jadi centangnya kembali.
        $this->selectBack($sku);
        $this->set($sku, $this->quantity($sku) + $quantity);
    }

    public function remove(string $sku): void
    {
        $this->session->forget(self::KEY.'.'.$this->key($sku));
        $this->selectBack($sku);
    }

    /**
     * Tentukan item mana yang terpilih untuk checkout; sisanya tetap di keranjang.
     *
     * @param  list<string>  $skus
     */
    public function select(array $skus): void
    {
        $selected = array_map($this->key(...), $skus);

        $this->session->put(self::UNSELECTED, array_values(array_diff(array_keys($this->session->get(self::KEY, [])), $selected)));
    }

    public function clear(): void
    {
        $this->session->forget([self::KEY, self::UNSELECTED]);
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
     * Subtotal, hemat, berat, dan can_checkout hanya menghitung item terpilih (item tidak tersedia tidak bisa dipilih).
     *
     * @return array{items: list<array<string, mixed>>, subtotal: int, saving: int, weight_gram: int, can_checkout: bool, selected_count: int}
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
        // Harga efektif (setelah diskon produk) dibaca ulang dari database setiap kali, jadi diskon yang berakhir ikut berakhir.
        $prices = $this->discounts->prices($variants, $this->discounts->memberId());

        $unselected = $this->session->get(self::UNSELECTED, []);

        $items = $entries->values()->map(function (array $entry) use ($variants, $available, $prices, $unselected) {
            /** @var ProductVariant|null $variant */
            $variant = $variants->get($entry['sku']);

            if (! $variant) {
                return null;
            }

            $sellable = $variant->is_active && $variant->product->is_published;
            $stock = $available[$variant->id] ?? 0;

            return [
                'selected' => $sellable && ! in_array($this->key($variant->sku), $unselected, true),
                'sku' => $variant->sku,
                'variant_id' => $variant->id,
                'product_name' => $variant->product->name,
                'variant_name' => $variant->name,
                'photo' => $variant->product->images->first()?->path,
                'price' => $prices[$variant->id],
                // Harga coret: harga normal yang diisi admin, atau harga jual varian sebelum diskon produk.
                'normal' => $variant->compare_at_price ?: $variant->price,
                'compare_at_price' => $variant->compare_at_price,
                'weight_gram' => $variant->weight_gram,
                'quantity' => min($entry['quantity'], $variant->max_order_quantity),
                'max_quantity' => $variant->max_order_quantity,
                'line_total' => $prices[$variant->id] * min($entry['quantity'], $variant->max_order_quantity),
                'issue' => ! $sellable ? 'unavailable' : ($stock < min($entry['quantity'], $variant->max_order_quantity) ? 'insufficient_stock' : null),
                'available' => $sellable ? $stock : 0,
            ];
        })->filter()->values();

        $picked = $items->where('selected')->values();

        return [
            'items' => $items->all(),
            'selected_count' => $picked->count(),
            'subtotal' => (int) $picked->sum('line_total'),
            // Total hemat dari diskon produk dan harga coret, hanya untuk ditampilkan; yang dibayar tetap subtotal.
            'saving' => (int) $picked->sum(fn (array $item) => max(0, $item['normal'] - $item['price']) * $item['quantity']),
            'weight_gram' => (int) $picked->sum(fn (array $item) => $item['weight_gram'] * $item['quantity']),
            'can_checkout' => $picked->isNotEmpty() && $picked->every(fn (array $item) => $item['issue'] === null),
        ];
    }

    private function selectBack(string $sku): void
    {
        $this->session->put(self::UNSELECTED, array_values(array_diff($this->session->get(self::UNSELECTED, []), [$this->key($sku)])));
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
