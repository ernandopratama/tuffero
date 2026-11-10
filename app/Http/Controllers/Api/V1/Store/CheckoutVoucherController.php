<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\CheckoutRequest;
use App\Models\Campaign;
use App\Services\CartService;
use App\Services\VoucherService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pilihan voucher di checkout. Hanya membaca dan menghitung; pemakaian terjadi di CheckoutService.
 */
class CheckoutVoucherController extends Controller
{
    use ApiResponse;

    public function __construct(private VoucherService $vouchers, private CartService $cart) {}

    public function index(Request $request): JsonResponse
    {
        return $this->success('Vouchers retrieved.', [
            'vouchers' => $this->vouchers->listFor($this->cart->summary()['subtotal'], $this->memberId($request), $this->phone($request)),
        ]);
    }

    /** Voucher yang perlu diklaim; tamu boleh melihat, klaimnya butuh akun. */
    public function claimable(Request $request): JsonResponse
    {
        $campaign = $request->query('campaign');
        $campaignId = is_string($campaign) && $campaign !== '' ? (int) Campaign::where('slug', $campaign)->value('id') : null;

        // Slug kampanye yang tidak ada: daftar kosong, bukan semua voucher.
        if (is_string($campaign) && $campaign !== '' && ! $campaignId) {
            return $this->success('Vouchers retrieved.', ['vouchers' => []]);
        }

        return $this->success('Vouchers retrieved.', ['vouchers' => $this->vouchers->claimable($this->memberId($request), $campaignId)]);
    }

    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required_without:codes', 'nullable', 'string', 'max:40'],
            'codes' => ['required_without:code', 'nullable', 'array', 'max:2'],
            'codes.*' => ['string', 'max:40'],
        ], ['code.required_without' => 'voucher_invalid', 'codes.required_without' => 'voucher_invalid', 'codes.max' => 'voucher_limit']);

        return $this->success('Voucher valid.', $this->vouchers->check(
            $request->input('codes') ?: [$request->input('code')],
            $this->cart->summary()['subtotal'],
            $this->memberId($request),
            $this->phone($request),
        ));
    }

    // Member = pelanggan yang login. Akun staf tidak dihitung pelanggan.
    private function memberId(Request $request): ?int
    {
        $user = $request->user();

        return $user && $user->getRoleNames()->isEmpty() ? $user->id : null;
    }

    private function phone(Request $request): ?string
    {
        $phone = $request->input('customer_phone');

        return is_string($phone) && $phone !== '' ? CheckoutRequest::normalizePhone($phone) : null;
    }
}
