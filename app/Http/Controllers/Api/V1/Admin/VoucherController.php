<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VoucherRequest;
use App\Models\Voucher;
use App\Services\PromoService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoucherController extends Controller
{
    use ApiResponse;

    public function __construct(private PromoService $promo) {}

    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->toString();

        $page = Voucher::when($search !== '', fn ($query) => $query->where('code', 'like', "%{$search}%"))
            ->orderByDesc('id')
            ->paginate(25);

        return $this->success('Vouchers retrieved.', [
            'items' => $page->items(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(VoucherRequest $request): JsonResponse
    {
        return $this->success('Voucher created.', $this->promo->createVoucher($request->validated(), $request->user()), 201);
    }

    public function update(VoucherRequest $request, Voucher $voucher): JsonResponse
    {
        return $this->success('Voucher updated.', $this->promo->updateVoucher($voucher, $request->validated(), $request->user()));
    }
}
