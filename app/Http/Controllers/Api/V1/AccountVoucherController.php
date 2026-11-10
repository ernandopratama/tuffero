<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\VoucherService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Voucher Saya": voucher yang diklaim akun pelanggan (D-072). Akun staf bukan pelanggan.
 */
class AccountVoucherController extends Controller
{
    use ApiResponse;

    public function __construct(private VoucherService $vouchers) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureCustomer($request);

        return $this->success('Vouchers retrieved.', ['items' => $this->vouchers->wallet($request->user()->id)]);
    }

    public function claim(Request $request, string $code): JsonResponse
    {
        $this->ensureCustomer($request);

        $voucher = $this->vouchers->claim($code, $request->user()->id);

        return $this->success('Voucher claimed.', ['code' => $voucher->code]);
    }

    private function ensureCustomer(Request $request): void
    {
        abort_if($request->user()->getRoleNames()->isNotEmpty(), 403);
    }
}
