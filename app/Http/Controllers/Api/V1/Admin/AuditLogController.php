<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $action = $request->string('action')->trim()->toString();
        $search = $request->string('search')->trim()->toString();

        $page = AuditLog::with('actor:id,name')
            ->when($action !== '', fn ($query) => $query->where('action', $action))
            ->when($search !== '', fn ($query) => $query->whereHas('actor', fn ($actor) => $actor->where('name', 'like', "%{$search}%")))
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 50));

        return $this->success('Audit logs retrieved.', [
            'items' => $page->getCollection()->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor?->name,
                'target' => class_basename($log->auditable_type).' #'.$log->auditable_id,
                'changes' => $log->changes,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }
}
