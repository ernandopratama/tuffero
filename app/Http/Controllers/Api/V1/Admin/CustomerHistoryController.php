<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Refund;
use App\Traits\ApiResponse;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Http\JsonResponse;

/**
 * Riwayat satu pelanggan untuk CS (D-107). Pelanggan tamu tidak punya akun, jadi riwayat dicari dari kontak yang sama
 * (HP atau email) pada pesanan lain, refund, dan pembatalan. Hanya nomor, tanggal, dan status; tanpa HP, email, alamat, atau nominal.
 * Tiap jenis hanya disertakan bila pengguna punya izin lihat jenis itu; izin target dan `search.quick` dijaga di route.
 */
class CustomerHistoryController extends Controller
{
    use ApiResponse;

    private const LIMIT = 10;

    public function forOrder(Order $order): JsonResponse
    {
        return $this->history($order, null, null);
    }

    public function forRefund(Refund $refund): JsonResponse
    {
        return $this->history($refund->order, $refund, null);
    }

    public function forCancellation(OrderCancellationRequest $cancellation): JsonResponse
    {
        return $this->history($cancellation->order, null, $cancellation);
    }

    private function history(Order $order, ?Refund $refund, ?OrderCancellationRequest $cancellation): JsonResponse
    {
        $user = request()->user();
        $groups = [];

        if ($user->can('orders.view')) {
            $rows = Order::where('id', '!=', $order->id)->where(fn ($q) => $this->sameContact($q, $order, 'customer_phone', 'customer_email'))
                ->latest('id')->limit(self::LIMIT)->get(['id', 'order_number', 'status', 'created_at'])
                ->map(fn (Order $o) => $this->row($o->id, $o->order_number, $o->created_at->format('d M Y'), $o->status, $o->order_number))->all();
            $groups[] = ['key' => 'orders', 'label' => 'Pesanan lain', 'route' => 'AdminOrders', 'rows' => $rows];
        }

        if ($user->can('refunds.view')) {
            $rows = Refund::with('order:id,order_number')->when($refund, fn ($q) => $q->where('id', '!=', $refund->id))
                ->where(fn ($q) => $q->whereHas('order', fn ($o) => $this->sameContact($o, $order, 'customer_phone', 'customer_email'))
                    ->orWhere(fn ($r) => $this->sameContact($r, $order, 'requester_phone', 'requester_email')))
                ->latest('id')->limit(self::LIMIT)->get()
                ->map(fn (Refund $r) => $this->row($r->id, $r->refund_number, $r->created_at->format('d M Y').' · '.$r->order->order_number, $r->status, $r->refund_number))->all();
            $groups[] = ['key' => 'refunds', 'label' => 'Refund', 'route' => 'AdminRefunds', 'rows' => $rows];
        }

        if ($user->canAny(['orders.cancel_request', 'orders.cancel', 'orders.cancel_final'])) {
            $rows = OrderCancellationRequest::with('order:id,order_number')->when($cancellation, fn ($q) => $q->where('id', '!=', $cancellation->id))
                ->whereHas('order', fn ($o) => $this->sameContact($o, $order, 'customer_phone', 'customer_email'))
                ->latest('id')->limit(self::LIMIT)->get()
                ->map(fn (OrderCancellationRequest $c) => $this->row($c->id, $c->request_number, $c->created_at->format('d M Y').' · '.$c->order->order_number, $c->status, $c->request_number))->all();
            $groups[] = ['key' => 'cancellations', 'label' => 'Pembatalan', 'route' => 'AdminCancellations', 'rows' => $rows];
        }

        return $this->success('History retrieved.', ['groups' => $groups]);
    }

    /**
     * Cocok bila HP atau email sama (HP boleh ditulis 08... atau 62...; email tanpa membedakan huruf besar).
     */
    private function sameContact(BuilderContract $query, Order $order, string $phoneColumn, string $emailColumn): void
    {
        $phones = $this->phoneVariants((string) $order->customer_phone);
        $email = mb_strtolower(trim((string) $order->customer_email));

        $query->where(function ($inner) use ($phones, $email, $phoneColumn, $emailColumn) {
            if ($phones !== []) {
                $inner->orWhereIn($phoneColumn, $phones);
            }
            if ($email !== '') {
                $inner->orWhereRaw("LOWER({$emailColumn}) = ?", [$email]);
            }
            // Tanpa HP maupun email tidak ada yang bisa dicocokkan: kondisi yang selalu salah menjaga hasil tetap kosong.
            if ($phones === [] && $email === '') {
                $inner->whereRaw('1 = 0');
            }
        });
    }

    /** @return list<string> */
    private function phoneVariants(string $phone): array
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) < 8) {
            return [];
        }

        return array_values(array_unique([$phone, $digits, str_starts_with($digits, '62') ? '0'.substr($digits, 2) : (str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits)]));
    }

    /** @return array<string, mixed> */
    private function row(int $id, string $title, string $subtitle, string $status, string $search): array
    {
        return ['id' => $id, 'title' => $title, 'subtitle' => $subtitle, 'status' => $status, 'search' => $search];
    }
}
