<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CaptionGenerateRequest;
use App\Models\AuditLog;
use App\Models\CaptionGeneration;
use App\Services\CaptionGenerator;
use App\Services\Marketing\MarketingModelException;
use App\Services\MarketingGenerator;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Generator Caption untuk tim marketing (`marketing_drafts.manage`): kirim isi video atau prompt, terima tiga variasi caption. */
class CaptionGeneratorController extends Controller
{
    use ApiResponse;

    private const HISTORY = 30;

    public function index(): JsonResponse
    {
        return $this->success('Caption history retrieved.', [
            'ai_enabled' => MarketingGenerator::enabled(),
            'items' => CaptionGeneration::with('user:id,name')->orderByDesc('id')->limit(self::HISTORY)->get()->map($this->present(...))->values(),
        ]);
    }

    public function store(CaptionGenerateRequest $request, CaptionGenerator $generator): JsonResponse
    {
        try {
            $generation = $generator->generate($request->validated(), $request->user());
        } catch (MarketingModelException $exception) {
            // Pesan sudah aman untuk staf: hanya jenis kegagalan, tanpa isi prompt atau jawaban.
            report($exception);

            return $this->error($exception->getMessage(), [], 503);
        }

        return $this->success('Captions generated.', $this->present($generation->load('user:id,name')), 201);
    }

    public function destroy(Request $request, CaptionGeneration $generation): JsonResponse
    {
        AuditLog::record('caption.deleted', $generation, ['platform' => $generation->platform], $request->user()->id);
        $generation->delete();

        return $this->success('Caption history deleted.');
    }

    /** @return array<string, mixed> */
    private function present(CaptionGeneration $generation): array
    {
        return [
            'id' => $generation->public_id,
            'platform' => $generation->platform,
            'tone' => $generation->tone,
            'brief' => $generation->brief,
            'captions' => $generation->captions,
            'author' => $generation->user?->name,
            'created_at' => $generation->created_at,
        ];
    }
}
