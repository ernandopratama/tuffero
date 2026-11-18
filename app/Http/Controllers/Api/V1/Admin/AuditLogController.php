<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

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

        $targets = $this->publicKeys($page->getCollection());

        return $this->success('Audit logs retrieved.', [
            'items' => $page->getCollection()->map(fn (AuditLog $log) => [
                'id' => $log->public_id,
                'action' => $log->action,
                'actor' => $log->actor?->name,
                // ID publik sasaran bila ada; ID angka tidak ditampilkan (D-143). Rujukan angka di `changes` lama dibiarkan.
                'target' => trim(class_basename((string) $log->auditable_type).' '.($targets[$log->auditable_type][$log->auditable_id] ?? '')),
                'changes' => $log->changes,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * ID publik sasaran per jenis data: `public_id`, `public_ref`, atau `key` (pengaturan). Kelas yang sudah tidak ada
     * dilewati; data yang dihapus lunak tetap terbaca. Satu kueri per jenis, bukan per baris.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return array<string, array<int|string, string>>
     */
    private function publicKeys(Collection $logs): array
    {
        $keys = [];
        foreach ($logs->groupBy('auditable_type') as $type => $rows) {
            if (! is_string($type) || ! class_exists($type) || ! is_subclass_of($type, Model::class)) {
                continue;
            }
            $model = new $type;
            $column = collect(['public_id', 'public_ref', 'key'])->first(fn (string $c) => Schema::hasColumn($model->getTable(), $c));
            if ($column === null) {
                continue;
            }
            $keys[$type] = $type::query()->withoutGlobalScopes()->whereIn($model->getKeyName(), $rows->pluck('auditable_id')->unique()->values())
                ->pluck($column, $model->getKeyName())->map(fn ($v) => (string) $v)->all();
        }

        return $keys;
    }
}
