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

        $page = Voucher::withCount('claims')->when($search !== '', fn ($query) => $query->where('code', 'like', "%{$search}%"))
            ->when(in_array($request->query('type'), ['percent', 'fixed'], true), fn ($query) => $query->where('type', $request->query('type')))
            ->when($request->query('is_active') !== null && $request->query('is_active') !== '', fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

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
