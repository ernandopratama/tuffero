<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReplyTemplateRequest;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Refund;
use App\Models\ReplyTemplate;
use App\Services\ReplyTemplateService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class ReplyTemplateController extends Controller
{
    use ApiResponse;

    public function __construct(private ReplyTemplateService $templates) {}

    public function index(): JsonResponse
    {
        $items = ReplyTemplate::orderBy('sort_order')->orderBy('id')->get()->map(fn (ReplyTemplate $template) => $this->present($template));

        return $this->success('Templates retrieved.', ['items' => $items, 'variables' => ReplyTemplate::VARIABLES]);
    }

    public function store(ReplyTemplateRequest $request): JsonResponse
    {
        return $this->success('Template created.', $this->present($this->templates->create($request->validated(), $request->user())), 201);
    }

    public function update(ReplyTemplateRequest $request, ReplyTemplate $template): JsonResponse
    {
        return $this->success('Template updated.', $this->present($this->templates->update($template, $request->validated(), $request->user())));
    }

    /** Template aktif dengan variabel sudah terisi dari data pesanan; izin lihat pesanan dijaga di route. */
    public function forOrder(Order $order): JsonResponse
    {
        return $this->success('Templates rendered.', $this->payload($order));
    }

    public function forRefund(Refund $refund): JsonResponse
    {
        return $this->success('Templates rendered.', $this->payload($refund));
    }

    public function forCancellation(OrderCancellationRequest $cancellation): JsonResponse
    {
        return $this->success('Templates rendered.', $this->payload($cancellation));
    }

    /** @return array<string, mixed> */
    private function payload(Order|Refund|OrderCancellationRequest $target): array
    {
        return ['items' => $this->templates->renderFor($target), 'whatsapp' => $this->templates->whatsappNumber($target)];
    }

    /** @return array<string, mixed> */
    private function present(ReplyTemplate $template): array
    {
        return $template->only(['id', 'title', 'body', 'scope', 'sort_order', 'is_active']);
    }
}
