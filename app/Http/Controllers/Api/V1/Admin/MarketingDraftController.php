<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MarketingDraftRequest;
use App\Http\Requests\Admin\MarketingGenerateRequest;
use App\Models\MarketingDraft;
use App\Services\Marketing\MarketingModelException;
use App\Services\MarketingDraftService;
use App\Services\MarketingGenerator;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Draf konten AI Marketing (D-062, D-079). Lihat: semua role admin (`marketing_drafts.view`). Susun dan ajukan: Marketing dan
 * Super Admin (`marketing_drafts.manage`). Putuskan: Pemilik dan Super Admin (`marketing_drafts.approve`).
 */
class MarketingDraftController extends Controller
{
    use ApiResponse;

    public function __construct(private MarketingDraftService $drafts) {}

    public function index(): JsonResponse
    {
        $items = MarketingDraft::with(['creator:id,name', 'reviewer:id,name'])->orderByRaw('scheduled_on is null')->orderBy('scheduled_on')->orderByDesc('id')->limit(200)->get();

        // Pencapaian minggu ini terhadap target: draf yang tidak ditolak dan jadwalnya jatuh di minggu berjalan.
        $week = $items->filter(fn (MarketingDraft $d) => $d->status !== MarketingDraft::REJECTED && $d->scheduled_on && $d->scheduled_on->isCurrentWeek());

        return $this->success('Drafts retrieved.', [
            'items' => $items->map($this->present(...))->values(),
            'frequency' => MarketingDraftService::frequency(),
            'ai_enabled' => MarketingGenerator::enabled(),
            'week' => [
                'posts' => $week->whereIn('type', ['caption', 'calendar_item'])->count(),
                'videos' => $week->where('type', 'video_script')->count(),
            ],
        ]);
    }

    public function store(MarketingDraftRequest $request): JsonResponse
    {
        return $this->success('Draft created.', $this->present($this->drafts->create($request->validated(), $request->user())), 201);
    }

    /** Buat draf dengan Claude (D-079). Hasilnya draf biasa berstatus `draft`; harus diajukan dan disetujui seperti draf manual. */
    public function generate(MarketingGenerateRequest $request, MarketingGenerator $generator): JsonResponse
    {
        try {
            $draft = $generator->generate($request->validated(), $request->user());
        } catch (MarketingModelException $exception) {
            // Pesan sudah aman untuk staf: hanya jenis kegagalan, tanpa isi prompt atau jawaban.
            report($exception);

            return $this->error($exception->getMessage(), [], 503);
        }

        return $this->success('Draft generated.', $this->present($draft), 201);
    }

    public function update(MarketingDraftRequest $request, MarketingDraft $draft): JsonResponse
    {
        return $this->success('Draft updated.', $this->present($this->drafts->update($draft, $request->validated(), $request->user())));
    }

    public function move(Request $request, MarketingDraft $draft): JsonResponse
    {
        $action = $request->validate(['action' => ['required', Rule::in(['submit', 'withdraw', 'post'])]])['action'];

        return $this->success('Draft moved.', $this->present($this->drafts->move($draft, $action, $request->user())));
    }

    public function decide(Request $request, MarketingDraft $draft): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:500', 'required_if:decision,reject'],
        ], ['note.required_if' => 'Tulis alasan penolakan.']);

        return $this->success('Draft decided.', $this->present($this->drafts->decide($draft, $data['decision'] === 'approve', $data['note'] ?? null, $request->user())));
    }

    public function destroy(Request $request, MarketingDraft $draft): JsonResponse
    {
        $this->drafts->delete($draft, $request->user());

        return $this->success('Draft deleted.');
    }

    /** @return array<string, mixed> */
    private function present(MarketingDraft $draft): array
    {
        $draft->loadMissing(['creator:id,name', 'reviewer:id,name']);

        return [
            ...$draft->only(['id', 'type', 'platform', 'title', 'body', 'sources', 'source_period', 'status', 'origin', 'review_note']),
            'scheduled_on' => $draft->scheduled_on?->toDateString(),
            'submitted_at' => $draft->submitted_at,
            'reviewed_at' => $draft->reviewed_at,
            'posted_at' => $draft->posted_at,
            'creator' => $draft->creator?->name,
            'reviewer' => $draft->reviewer?->name,
        ];
    }
}
