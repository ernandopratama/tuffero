<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Refund;
use Carbon\CarbonInterface;

/**
 * Satu sumber angka pendapatan untuk ringkasan Pemilik dan halaman Laporan (keputusan pengguna 13 Nov 2026, lihat CHANGELOG).
 *
 * - Kotor (D-131, 17 Nov 2026): pesanan yang `paid_at`-nya jatuh di periode dan TIDAK dibatalkan (`cancelled_at` kosong). Pesanan batal
 *   keluar dari angka ini seluruhnya, termasuk di bulan bayarnya, dan tampil sebagai data tersendiri di halaman Laporan. Konsekuensinya angka
 *   bulan lalu bisa turun bila pesanan bulan itu dibatalkan kemudian.
 * - Dikembalikan: uang yang keluar di periode (tanggal dana ditandai sudah dikembalikan) untuk refund barang bermasalah atau retur yang
 *   pelanggan pilih sebagai dana kembali: nilai disetujui ditambah ongkir. Dana pembatalan TIDAK ikut (pesanannya sudah di luar kotor, jadi
 *   dikurangkan lagi akan menghitung dua kali); lihat `cancellationRefunds()` untuk data pembatalan. Kirim ulang dan voucher pengganti
 *   tidak mengeluarkan uang, jadi tidak dihitung.
 * - Bersih: kotor dikurangi dikembalikan, dan bisa negatif pada bulan ketika dana yang dikembalikan lebih besar dari penjualannya.
 *
 * Periode berupa pasangan [awal, akhir] yang sudah dalam UTC (lihat DashboardService::monthRange()).
 */
class RevenueService
{
    /** @param  array{0: CarbonInterface, 1: CarbonInterface}  $range */
    public function gross(array $range): int
    {
        return (int) Order::whereBetween('paid_at', $range)->whereNull('cancelled_at')->sum('total');
    }

    /**
     * Jumlah pesanan yang membentuk `gross`.
     *
     * @param  array{0: CarbonInterface, 1: CarbonInterface}  $range
     */
    public function paidCount(array $range): int
    {
        return Order::whereBetween('paid_at', $range)->whereNull('cancelled_at')->count();
    }

    /** @param  array{0: CarbonInterface, 1: CarbonInterface}  $range */
    public function refunded(array $range): int
    {
        $claims = $this->claimRefunds($range);

        return $claims['return'] + $claims['claim'];
    }

    /**
     * Dana pembatalan pesanan terbayar yang dikembalikan di periode (tanggal `refunded_at`), sebesar total pesanan. Data pembatalan, bukan bagian `refunded()`.
     *
     * @param  array{0: CarbonInterface, 1: CarbonInterface}  $range
     */
    public function cancellationRefunds(array $range): int
    {
        return (int) OrderCancellationRequest::join('orders', 'orders.id', '=', 'order_cancellation_requests.order_id')
            ->whereBetween('order_cancellation_requests.refunded_at', $range)->sum('orders.total');
    }

    /**
     * Dana refund barang bermasalah dan retur yang dikembalikan di periode, dipisah retur produk utuh (D-097) dan klaim lain (D-130).
     *
     * @param  array{0: CarbonInterface, 1: CarbonInterface}  $range
     * @return array{return: int, claim: int, return_count: int, claim_count: int}
     */
    public function claimRefunds(array $range): array
    {
        $row = Refund::where('status', Refund::REFUNDED)->where('customer_choice', 'refund')->whereBetween('refunded_at', $range)
            ->selectRaw('COALESCE(SUM(CASE WHEN reason_type = ? THEN COALESCE(approved_amount, 0) + shipping_amount ELSE 0 END), 0) AS return_sum', [Refund::RETURN_REASON])
            ->selectRaw('COALESCE(SUM(CASE WHEN reason_type <> ? THEN COALESCE(approved_amount, 0) + shipping_amount ELSE 0 END), 0) AS claim_sum', [Refund::RETURN_REASON])
            ->selectRaw('COALESCE(SUM(CASE WHEN reason_type = ? THEN 1 ELSE 0 END), 0) AS return_n', [Refund::RETURN_REASON])
            ->selectRaw('COUNT(*) AS total_n')
            ->toBase()->first();

        return ['return' => (int) $row->return_sum, 'claim' => (int) $row->claim_sum, 'return_count' => (int) $row->return_n, 'claim_count' => (int) $row->total_n - (int) $row->return_n];
    }

    /**
     * Subsidi gratis ongkir (D-126): selisih tarif kurir dan ongkir yang dibayar pelanggan, untuk pesanan yang lunas di periode.
     *
     * @param  array{0: CarbonInterface, 1: CarbonInterface}  $range
     */
    public function shippingSubsidy(array $range): int
    {
        return (int) Order::whereBetween('paid_at', $range)->whereNull('cancelled_at')->sum('shipping_discount');
    }

    /** @param  array{0: CarbonInterface, 1: CarbonInterface}  $range */
    public function net(array $range): int
    {
        return $this->gross($range) - $this->refunded($range);
    }
}
