<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReturnBlockRuleRequest;
use App\Services\ReturnBlockRuleService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aturan blokir otomatis retur (D-133). Melihat untuk `customer_blocks.view`; mengajukan nyala dan mematikan untuk
 * `customer_blocks.manage` (Super Admin, Admin Toko); memutuskan untuk `customer_blocks.approve` dan hanya peran Pemilik.
 */
class ReturnBlockRuleController extends Controller
{
    use ApiResponse;

    private const MESSAGES = ['note.required' => 'Catatan wajib diisi.', 'note.min' => 'Catatan minimal 5 karakter.', 'note.max' => 'Catatan maksimal 255 karakter.'];

    public function __construct(private ReturnBlockRuleService $rule) {}

    public function show(): JsonResponse
    {
        return $this->success('Rule retrieved.', $this->rule->state());
    }

    public function request(Request $request): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:255']], self::MESSAGES);
        $this->rule->requestEnable(trim($data['note']), $request->user());

        return $this->success('Request submitted.', $this->rule->state(), 201);
    }

    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:255']], self::MESSAGES);
        $this->rule->disable(trim($data['note']), $request->user());

        return $this->success('Rule disabled.', $this->rule->state());
    }

    public function decide(Request $request, ReturnBlockRuleRequest $ruleRequest): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'note' => [$request->input('decision') === 'reject' ? 'required' : 'nullable', 'string', 'min:5', 'max:255'],
        ], self::MESSAGES);

        $this->rule->decide($ruleRequest, $data['decision'] === 'approve', isset($data['note']) ? trim($data['note']) : null, $request->user());

        return $this->success('Request decided.', $this->rule->state());
    }
}
