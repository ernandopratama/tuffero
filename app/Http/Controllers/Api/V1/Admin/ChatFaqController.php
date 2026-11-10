<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChatFaqRequest;
use App\Models\ChatFaq;
use App\Services\ChatFaqService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class ChatFaqController extends Controller
{
    use ApiResponse;

    public function __construct(private ChatFaqService $faqs) {}

    public function index(): JsonResponse
    {
        $items = ChatFaq::orderBy('sort_order')->orderBy('id')->get()->map(fn (ChatFaq $faq) => $this->present($faq));

        return $this->success('FAQs retrieved.', ['items' => $items]);
    }

    public function store(ChatFaqRequest $request): JsonResponse
    {
        return $this->success('FAQ created.', $this->present($this->faqs->create($request->validated(), $request->user())), 201);
    }

    public function update(ChatFaqRequest $request, ChatFaq $faq): JsonResponse
    {
        return $this->success('FAQ updated.', $this->present($this->faqs->update($faq, $request->validated(), $request->user())));
    }

    /** @return array<string, mixed> */
    private function present(ChatFaq $faq): array
    {
        return $faq->only(['id', 'title', 'title_en', 'keywords', 'answer_id', 'answer_en', 'sort_order', 'is_active', 'is_suggested']);
    }
}
