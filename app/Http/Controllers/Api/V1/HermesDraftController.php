<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MarketingDraft;
use App\Services\MarketingDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Penerima laporan riset dari Hermes Agent (D-079). Bukan pengguna dashboard: dijaga token bersama (middleware HermesToken), bukan sesi.
 * Hasilnya draf `draft` biasa yang harus diperiksa, diajukan, dan disetujui seperti draf lain (BR-19). Hermes tidak bisa
 * mengubah status, menyunting, atau membaca draf; hanya menambah, dan kiriman ulang dengan `external_id` sama tidak menggandakan.
 */
class HermesDraftController extends Controller
{
    public function __invoke(Request $request, MarketingDraftService $drafts): JsonResponse
    {
        // Pesan galat Hermes dibaca staf; tetap hanya jenis dan kolom, tanpa mengulang isi kiriman.
        $trend = fn () => $request->input('type') === 'trend_report';
        $data = $request->validate([
            'external_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'type' => ['required', Rule::in(array_keys(MarketingDraft::TYPES))],
            'platform' => ['nullable', Rule::in(array_keys(MarketingDraft::PLATFORMS))],
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'sources' => [Rule::requiredIf($trend), 'nullable', 'string', 'max:1000'],
            'source_period' => [Rule::requiredIf($trend), 'nullable', 'string', 'max:100'],
        ]);

        $existing = MarketingDraft::withTrashed()->where('external_id', $data['external_id'])->first();
        if ($existing) {
            return response()->json(['success' => true, 'message' => 'Already received.', 'data' => ['id' => $existing->id]]);
        }

        $externalId = $data['external_id'];
        unset($data['external_id']);
        $draft = $drafts->create($data, null, MarketingDraft::HERMES, $externalId);

        return response()->json(['success' => true, 'message' => 'Draft received.', 'data' => ['id' => $draft->id]], 201);
    }
}
