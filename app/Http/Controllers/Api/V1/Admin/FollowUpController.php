<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FollowUp;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Refund;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Penanda tindak lanjut CS (D-105). Izin target dijaga di route; di sini hanya mengurus daftar, tambah, dan selesai.
 * Tanpa edit dan hapus: salah tulis ditandai selesai lalu dibuat ulang, sehingga riwayat tetap utuh.
 */
class FollowUpController extends Controller
{
    use ApiResponse;

    /** Daftar per data: yang belum selesai lebih dulu, lalu 5 yang terakhir selesai. */
    public function index(Request $request): JsonResponse
    {
        $target = $this->target($request);
        $open = $target->followUps()->with('creator:id,name')->whereNull('done_at')->orderBy('due_on')->get();
        $done = $target->followUps()->with('creator:id,name')->whereNotNull('done_at')->orderByDesc('done_at')->limit(5)->get();

        return $this->success('Follow-ups retrieved.', ['items' => $open->concat($done)->map(fn (FollowUp $followUp) => $this->present($followUp))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:200'],
            'due_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.today()->addDays(90)->toDateString()],
        ], [
            'note.required' => 'Catatan tindak lanjut wajib diisi.',
            'note.max' => 'Catatan maksimal 200 karakter.',
            'due_on.required' => 'Tanggal wajib diisi.',
            'due_on.after_or_equal' => 'Tanggal tidak boleh sebelum hari ini.',
            'due_on.before_or_equal' => 'Tanggal paling jauh 90 hari dari sekarang.',
        ]);

        $target = $this->target($request);
        $followUp = $target->followUps()->create(['note' => $validated['note'], 'due_on' => $validated['due_on'], 'created_by' => $request->user()->id]);
        AuditLog::record('follow_up.created', $target, ['follow_up_id' => $followUp->id, 'due_on' => $validated['due_on']], $request->user()->id);

        return $this->success('Follow-up created.', $this->present($followUp->load('creator:id,name')), 201);
    }

    /** Idempoten: menandai yang sudah selesai tidak mengubah apa pun. */
    public function complete(Request $request, FollowUp $followUp): JsonResponse
    {
        if ($followUp->done_at === null) {
            $followUp->forceFill(['done_at' => now(), 'done_by' => $request->user()->id])->save();
            AuditLog::record('follow_up.done', $followUp->followable, ['follow_up_id' => $followUp->id], $request->user()->id);
        }

        return $this->success('Follow-up completed.', $this->present($followUp->load('creator:id,name')));
    }

    private function target(Request $request): Order|Refund|OrderCancellationRequest
    {
        return $request->route('order') ?? $request->route('refund') ?? $request->route('cancellation');
    }

    /** @return array<string, mixed> */
    private function present(FollowUp $followUp): array
    {
        return [
            'id' => $followUp->id,
            'note' => $followUp->note,
            'due_on' => $followUp->due_on->toDateString(),
            'done_at' => $followUp->done_at,
            'is_overdue' => $followUp->isOverdue(),
            'created_by' => $followUp->creator->name,
        ];
    }
}
