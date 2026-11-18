<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MarketingDraftRequest;
use App\Http\Requests\Admin\MarketingGenerateRequest;
use App\Models\MarketingDraft;
use App\Models\MarketingDraftNote;
use App\Models\MarketingDraftReference;
use App\Services\ImageService;
use App\Services\Marketing\MarketingModelException;
use App\Services\MarketingDraftService;
use App\Services\MarketingGenerator;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

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
        $items = MarketingDraft::with(['creator:id,name', 'reviewer:id,name', 'references', 'notes.user:id,name'])->orderByRaw('scheduled_on is null')->orderBy('scheduled_on')->orderByDesc('id')->limit(200)->get();

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

    /** Catatan tim untuk AI; storyboard disusun ulang otomatis di antrean. */
    public function storeNote(Request $request, MarketingDraft $draft): JsonResponse
    {
        return $this->success('Note added.', $this->present($this->drafts->addNote($draft, $this->noteBody($request), $request->user())), 201);
    }

    public function updateNote(Request $request, MarketingDraft $draft, MarketingDraftNote $note): JsonResponse
    {
        abort_unless((int) $note->marketing_draft_id === (int) $draft->id, 404);

        return $this->success('Note updated.', $this->present($this->drafts->updateNote($draft, $note, $this->noteBody($request), $request->user())));
    }

    public function destroyNote(Request $request, MarketingDraft $draft, MarketingDraftNote $note): JsonResponse
    {
        abort_unless((int) $note->marketing_draft_id === (int) $draft->id, 404);

        return $this->success('Note deleted.', $this->present($this->drafts->deleteNote($draft, $note, $request->user())));
    }

    private function noteBody(Request $request): string
    {
        return $request->validate(['body' => ['required', 'string', 'max:1000']], ['body.required' => 'Tulis catatannya.', 'body.max' => 'Catatan maksimal 1000 karakter.'])['body'];
    }

    public function storeReference(Request $request, MarketingDraft $draft): JsonResponse
    {
        $request->validate(
            ['image' => ['required', 'file', ImageService::MIMES, 'max:10240']],
            ['image.required' => 'Pilih foto.', 'image.mimes' => ImageService::MIME_MESSAGE, 'image.max' => 'Foto maksimal 10 MB.'],
        );

        return $this->success('Reference added.', $this->present($this->drafts->addReference($draft, $request->file('image'), $request->user())), 201);
    }

    /** Foto acuan hanya terbuka bagi akun admin yang boleh melihat draf; foto dari draf lain menjawab 404. */
    public function showReference(MarketingDraft $draft, MarketingDraftReference $reference): Response
    {
        abort_unless((int) $reference->marketing_draft_id === (int) $draft->id && Storage::disk('local')->exists($reference->path), 404);

        return Storage::disk('local')->response($reference->path, null, ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, max-age=600', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroyReference(Request $request, MarketingDraft $draft, MarketingDraftReference $reference): JsonResponse
    {
        abort_unless((int) $reference->marketing_draft_id === (int) $draft->id, 404);

        return $this->success('Reference deleted.', $this->present($this->drafts->deleteReference($draft, $reference, $request->user())));
    }

    /** @return array<string, mixed> */
    private function present(MarketingDraft $draft): array
    {
        $draft->loadMissing(['creator:id,name', 'reviewer:id,name', 'references', 'notes.user:id,name']);

        return [
            'id' => $draft->public_id,
            ...$draft->only(['type', 'platform', 'title', 'body', 'sources', 'source_period', 'status', 'origin', 'review_note', 'duration_seconds', 'music_note', 'visual_mode', 'storyboard', 'storyboard_status', 'storyboard_error']),
            'storyboard_at' => $draft->storyboard_at,
            'references' => $draft->references->map(fn (MarketingDraftReference $reference) => ['id' => $reference->public_id, 'url' => "/api/v1/admin/marketing-drafts/{$draft->public_id}/references/{$reference->public_id}"])->values(),
            'notes' => $draft->notes->map(fn ($note) => ['id' => $note->public_id, 'body' => $note->body, 'author' => $note->user?->name, 'created_at' => $note->created_at])->values(),
            'scheduled_on' => $draft->scheduled_on?->toDateString(),
            'submitted_at' => $draft->submitted_at,
            'reviewed_at' => $draft->reviewed_at,
            'posted_at' => $draft->posted_at,
            'creator' => $draft->creator?->name,
            'reviewer' => $draft->reviewer?->name,
        ];
    }
}
