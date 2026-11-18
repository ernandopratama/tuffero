<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentEvent;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ekspor penjualan untuk finance, mengikuti template impor tagihan penjualan (49 kolom, satu baris per item; kolom tingkat pesanan
 * diulang di tiap baris pesanan yang sama). Aturan pengisian diputuskan Ernando 18 Nov 2026 (lihat CHANGELOG). Isi: pesanan yang
 * dibayar di rentang (waktu Jakarta) dan tidak dibatalkan, sama dengan kelompok `RevenueService::gross` (D-131). Pesanan batal tidak
 * ikut, tetapi tetap tersimpan dan tampil di rekap Laporan.
 */
class SalesExportService
{
    /** Judul kolom persis seperti template (baris 1). */
    public const HEADERS = [
        '*Nama Kontak',
        'Email',
        'Alamat',
        'Nomor Telepon',
        'Email Sales Person',
        '*Nomor Tagihan',
        'Nomor Pesanan',
        '*Tanggal Transaksi (dd/mm/yyyy)',
        '*Tanggal Jatuh Tempo (dd/mm/yyyy)',
        '*Nama / Kode Gudang',
        'Catatan',
        'Tanggal Pengiriman (dd/mm/yyyy)',
        'Ekspedisi / Pengiriman Kurir',
        'Nomor Resi Pengiriman',
        '*Termasuk Pajak (Ya / Tidak)',
        '*Nama Produk',
        '*Kode Produk (SKU)',
        'Deskripsi Produk',
        '*Jumlah Produk',
        'Satuan Produk',
        'Diskon Produk',
        'Diskon Produk (Persen)',
        '*Harga Produk',
        'Pajak Produk',
        'Jumlah Diskon Tambahan',
        'Jumlah Diskon Tambahan (Persen)',
        'Biaya Pengiriman',
        'Nama Biaya Transaksi',
        'Jumlah Biaya Transaksi',
        'Kode Akun Biaya Transaksi',
        'Kode Akun Pemotongan',
        'Jumlah Pemotongan Tambahan',
        'Jumlah Pemotongan Tambahan (Persen)',
        'Kode Akun Uang Muka',
        'Jumlah Uang Muka',
        'Jumlah Uang Muka (Persen)',
        '*Status Dibayar (Ya / Tidak / Bertahap)',
        'Tanggal Pelunasan (dd/mm/yyyy)',
        'Jumlah Pembayaran',
        'Tanggal Pembayaran (dd/mm/yyyy)',
        'Kode Akun Pembayaran',
        'Referensi Pembayaran',
        'Tag (Beberapa Tag Dipisah Dengan Koma)',
        'Kode Faktur & Nomor Seri (Faktur Pajak)',
        'Link Attachment',
        'Nama / Kode Gudang Produk',
        'Tag Item (Beberapa Tag Dipisah Dengan Koma)',
        'Termin',
        'ID Kontak',
    ];

    /** Rentang terpanjang sekali ekspor, hari. */
    public const MAX_DAYS = 366;

    private const TZ = 'Asia/Jakarta';

    /** Tarif Midtrans (diberikan Ernando): jenis => [nama, biaya tetap, persen]. PPN 11% ditambahkan di `fee()`. */
    private const FEES = [
        'va' => ['Biaya Transaksi Midtrans - Virtual Account (termasuk PPN 11%)', 4000, 0.0],
        'qris' => ['Biaya Transaksi Midtrans - QRIS (termasuk PPN 11%)', 0, 0.007],
        'card' => ['Biaya Transaksi Midtrans - Kartu Kredit (termasuk PPN 11%)', 2000, 0.029],
    ];

    /** Jenis pembayaran Midtrans => kelompok tarif. gopay dan shopeepay tidak dipakai di Tuffero, jadi tanpa tarif. */
    private const FEE_GROUP = ['bank_transfer' => 'va', 'echannel' => 'va', 'qris' => 'qris', 'other_qris' => 'qris', 'credit_card' => 'card'];

    private const VAT = 0.11;

    /** @var array<int, string> id gudang => kode */
    private array $warehouses = [];

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} awal dan akhir rentang dalam UTC (tanggal Jakarta, kedua ujung termasuk) */
    public function range(string $from, string $to): array
    {
        return [
            CarbonImmutable::parse($from, self::TZ)->startOfDay()->setTimezone('UTC'),
            CarbonImmutable::parse($to, self::TZ)->endOfDay()->setTimezone('UTC'),
        ];
    }

    /**
     * Jumlah pesanan, baris, dan pesanan yang biaya transaksinya tidak bisa diisi (metode tanpa tarif atau tanpa event bayar).
     *
     * @param  array{0: CarbonInterface, 1: CarbonInterface}  $range
     * @return array{orders: int, rows: int, no_fee: int}
     */
    public function summary(array $range): array
    {
        $ids = $this->query($range)->pluck('id');
        $rows = $ids->isEmpty() ? 0 : (int) DB::table('order_items')->whereIn('order_id', $ids)->count();
        $noFee = 0;

        foreach ($ids->chunk(500) as $chunk) {
            $paid = $this->paidEvents($chunk->all());
            $noFee += $chunk->filter(fn ($id) => ! isset(self::FEE_GROUP[isset($paid[$id]) ? (string) $paid[$id]->payment_type : '']))->count();
        }

        return ['orders' => $ids->count(), 'rows' => $rows, 'no_fee' => $noFee];
    }

    /**
     * Baris data berurutan menurut id pesanan; dibaca per potongan supaya memori tetap kecil. Teks pelanggan dikembalikan apa adanya: penulis berkas wajib menulis teks sebagai sel teks (bukan rumus), lihat `SalesExportController`.
     *
     * @param  array{0: CarbonInterface, 1: CarbonInterface}  $range
     * @return Generator<int, list<string|int|float|null>>
     */
    public function rows(array $range): Generator
    {
        $this->warehouses = Warehouse::pluck('code', 'id')->all();
        $last = 0;

        while (true) {
            $orders = $this->query($range)->where('id', '>', $last)->with(['items.variant.discounts', 'shipment'])->orderBy('id')->limit(200)->get();

            if ($orders->isEmpty()) {
                return;
            }

            $paid = $this->paidEvents($orders->pluck('id')->all());

            foreach ($orders as $order) {
                foreach ($order->items as $item) {
                    yield $this->row($order, $item, $paid[$order->id] ?? null);
                }
            }

            $last = (int) $orders->last()->id;
        }
    }

    /**
     * @param  array{0: CarbonInterface, 1: CarbonInterface}  $range
     * @return Builder<Order>
     */
    private function query(array $range): Builder
    {
        return Order::whereBetween('paid_at', $range)->whereNull('cancelled_at');
    }

    /**
     * Event bayar terakhir (settlement atau capture) per pesanan.
     *
     * @param  list<int>  $orderIds
     * @return array<int, PaymentEvent>
     */
    private function paidEvents(array $orderIds): array
    {
        return PaymentEvent::whereIn('order_id', $orderIds)->whereIn('transaction_status', ['settlement', 'capture'])->orderBy('id')->get()->keyBy('order_id')->all();
    }

    /** @return list<string|int|float|null> */
    private function row(Order $order, OrderItem $item, ?PaymentEvent $event): array
    {
        $row = array_fill(0, count(self::HEADERS), null);
        $put = function (string $column, mixed $value) use (&$row): void {
            $row[$this->index($column)] = $value;
        };
        $local = fn (?CarbonInterface $date) => $date?->copy()->setTimezone(self::TZ);

        $address = (array) $order->shipping_address;
        $variant = $item->variant;
        // Harga jual dan harga normal dari varian (modal ubah varian); varian yang sudah dihapus memakai harga saat pesanan.
        $sell = (int) ($variant->price ?? $item->unit_price);
        $normal = (int) ($variant?->compare_at_price ?: $sell);
        $extra = $variant ? max(0, $sell - (int) $item->unit_price) : 0;
        $active = $variant ? $variant->discounts->where('is_active', true) : collect();
        $percentOnly = $active->isNotEmpty() && $active->every(fn ($d) => $d->type === 'percent');
        $fee = $this->fee($event?->payment_type, (int) ($event->gross_amount ?? 0));
        $voucher = array_values(array_filter([$order->voucher_code, $order->voucher_code_2]));
        $note = ($voucher ? 'Voucher: '.implode(', ', $voucher) : '').($order->shipping_voucher_code ? ($voucher ? '; ' : '').'Gratis ongkir: '.$order->shipping_voucher_code : '');

        $put('A', $order->customer_name);
        $put('B', $order->customer_email);
        $put('C', implode(', ', array_filter([$address['street'] ?? null, $address['district'] ?? null, $address['city'] ?? null, $address['province'] ?? null, $address['postal_code'] ?? null])));
        // Nomor disimpan +62...; finance memakai format 08... (seperti contoh template).
        $put('D', preg_replace('/^\+62/', '0', (string) $order->customer_phone));
        $put('F', $event->transaction_id ?? $order->order_number);
        $put('G', $order->order_number);
        $put('H', $local($order->created_at)?->format('d/m/Y'));
        $put('I', $local($order->paid_at)?->format('d/m/Y'));
        $put('J', $this->warehouses[$order->warehouse_id] ?? null);
        $put('K', $note !== '' ? $note : null);
        $put('L', $local($order->shipment?->shipped_at)?->format('d/m/Y'));
        $put('M', strtoupper(trim($order->shipping_courier.' '.$order->shipping_service)));
        $put('N', $order->shipment?->waybill_id);
        $put('O', 'Tidak');
        $put('P', $item->product_name_snapshot.' - '.$item->variant_name_snapshot);
        $put('Q', $item->sku_snapshot);
        $put('S', (int) $item->quantity);
        $put('U', $sell);
        $put('V', $normal > $sell ? round(($normal - $sell) / $normal * 100, 2) : null);
        $put('W', $normal);
        if ($extra > 0) {
            $percentOnly ? $put('Z', round($extra / $sell * 100, 2)) : $put('Y', $extra);
        }
        $put('AB', $fee['name'] ?? null);
        $put('AC', $fee['amount'] ?? null);
        $put('AF', (int) $order->discount_total > 0 ? (int) $order->discount_total : null);
        $put('AK', 'Ya');
        $put('AM', $event ? (int) $event->gross_amount : (int) $order->total);
        $put('AN', $this->paidAt($order, $event));
        $put('AQ', 'website');

        return $row;
    }

    /**
     * Biaya transaksi Midtrans sudah termasuk PPN 11%, dibulatkan ke rupiah. Metode tanpa tarif dikosongkan.
     *
     * @return array{name: string, amount: int}|null
     */
    public function fee(?string $paymentType, int $gross): ?array
    {
        $group = self::FEE_GROUP[$paymentType ?? ''] ?? null;

        if (! $group) {
            return null;
        }

        [$name, $fixed, $percent] = self::FEES[$group];

        return ['name' => $name, 'amount' => (int) round(($fixed + $gross * $percent) * (1 + self::VAT))];
    }

    /** Waktu Midtrans mencatat pembayaran (WIB, dd/mm/yyyy HH:mm); cadangan `paid_at`. */
    private function paidAt(Order $order, ?PaymentEvent $event): ?string
    {
        $payload = (array) ($event->payload ?? []);
        $time = $payload['settlement_time'] ?? $payload['transaction_time'] ?? null;

        if ($time) {
            try {
                return CarbonImmutable::parse($time, self::TZ)->format('d/m/Y H:i');
            } catch (Throwable) {
                // jatuh ke paid_at
            }
        }

        return $order->paid_at?->copy()->setTimezone(self::TZ)->format('d/m/Y H:i');
    }

    private function index(string $column): int
    {
        $n = 0;
        foreach (str_split($column) as $char) {
            $n = $n * 26 + (ord($char) - 64);
        }

        return $n - 1;
    }
}
