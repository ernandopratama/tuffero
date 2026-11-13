<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Refund;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Satu sumber angka pendapatan untuk ringkasan Pemilik dan halaman Laporan (keputusan pengguna 13 Nov 2026, lihat CHANGELOG).
 *
 * - Kotor: semua pesanan yang `paid_at`-nya jatuh di periode, apa pun status bayarnya sekarang. Pembatalan tidak menghapus pesanan
 *   dari bulan asalnya, jadi angka bulan yang sudah lewat tidak berubah mundur.
 * - Dikembalikan: uang yang benar-benar keluar di periode (tanggal dana ditandai sudah dikembalikan, bukan tanggal persetujuan):
 *   pembatalan pesanan terbayar sebesar total pesanan, dan refund barang bermasalah atau retur yang pelanggan pilih sebagai dana kembali
 *   sebesar nilai disetujui ditambah ongkir. Kirim ulang dan voucher pengganti tidak mengeluarkan uang, jadi tidak dihitung.
 * - Bersih: kotor dikurangi dikembalikan, dan bisa negatif pada bulan ketika dana yang dikembalikan lebih besar dari penjualannya.
 *
 * Periode berupa pasangan [awal, akhir] yang sudah dalam UTC (lihat DashboardService::monthRange()).
 */
class RevenueService
{
    /** @param  array{0: CarbonInterface, 1: CarbonInterface}  $range */
    public function gross(array $range): int
    {
        return (int) Order::whereBetween('paid_at', $range)->sum('total');
    }

    /** @param  array{0: CarbonInterface, 1: CarbonInterface}  $range */
    public function refunded(array $range): int
    {
        $cancelled = (int) OrderCancellationRequest::join('orders', 'orders.id', '=', 'order_cancellation_requests.order_id')
            ->whereBetween('order_cancellation_requests.refunded_at', $range)->sum('orders.total');

        $claims = (int) Refund::where('status', Refund::REFUNDED)->where('customer_choice', 'refund')
            ->whereBetween('refunded_at', $range)->sum(DB::raw('COALESCE(approved_amount, 0) + shipping_amount'));

        return $cancelled + $claims;
    }

    /** @param  array{0: CarbonInterface, 1: CarbonInterface}  $range */
    public function net(array $range): int
    {
        return $this->gross($range) - $this->refunded($range);
    }
}
