<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VoucherRequest;
use App\Models\Campaign;
use App\Models\Voucher;
use App\Services\PromoService;
use App\Services\VoucherService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoucherController extends Controller
{
    use ApiResponse;

    public function __construct(private PromoService $promo, private VoucherService $vouchers) {}

    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->toString();

        $page = Voucher::with('campaign:id,public_id')->withCount('claims')->when($search !== '', fn ($query) => $query->where('code', 'like', "%{$search}%"))
            ->when(in_array($request->query('type'), ['percent', 'fixed'], true), fn ($query) => $query->where('type', $request->query('type')))
            ->when(in_array($request->query('kind'), [Voucher::KIND_ORDER, Voucher::KIND_SHIPPING], true), fn ($query) => $query->where('kind', $request->query('kind')))
            ->when($request->query('is_active') !== null && $request->query('is_active') !== '', fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));
        // Terpakai dari anggaran (D-126): potongan pesanan yang tidak batal.
        $page->getCollection()->each(fn (Voucher $voucher) => $voucher->setAttribute('spent', $this->vouchers->spent($voucher)));

        return $this->success('Vouchers retrieved.', [
            'items' => collect($page->items())->map(fn (Voucher $voucher) => $this->present($voucher))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(VoucherRequest $request): JsonResponse
    {
        return $this->success('Voucher created.', $this->present($this->promo->createVoucher($this->voucherData($request), $request->user())), 201);
    }

    public function update(VoucherRequest $request, Voucher $voucher): JsonResponse
    {
        return $this->success('Voucher updated.', $this->present($this->promo->updateVoucher($voucher, $this->voucherData($request), $request->user())));
    }

    /**
     * Bentuk voucher untuk admin: ID dan kampanye memakai ID publik, ID angka tidak dikirim (D-143).
     *
     * @return array<string, mixed>
     */
    private function present(Voucher $voucher): array
    {
        $row = $voucher->toArray();
        unset($row['id'], $row['public_id'], $row['campaign_id'], $row['campaign']);

        return ['id' => $voucher->public_id, 'campaign_id' => $voucher->campaign?->public_id] + $row;
    }

    /** @return array<string, mixed> */
    private function voucherData(VoucherRequest $request): array
    {
        $data = $request->validated();
        if (array_key_exists('campaign_id', $data)) {
            $data['campaign_id'] = $data['campaign_id'] === null ? null : Campaign::where('public_id', $data['campaign_id'])->value('id');
        }

        return $data;
    }
}
