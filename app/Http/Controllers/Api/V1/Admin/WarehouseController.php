<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\WarehouseRequest;
use App\Models\AuditLog;
use App\Models\Warehouse;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        return $this->success('Warehouses retrieved.', ['items' => Warehouse::orderBy('id')->get()->map(fn (Warehouse $w) => $this->present($w))]);
    }

    public function update(WarehouseRequest $request, Warehouse $warehouse): JsonResponse
    {
        // Pemeriksaan "gudang aktif terakhir" di request hanya cepat; yang mengikat ada di sini, di bawah kunci,
        // supaya dua perubahan bersamaan tidak sama-sama menonaktifkan gudang dan menyisakan nol gudang aktif.
        DB::transaction(function () use ($request, $warehouse) {
            Warehouse::where('is_active', true)->lockForUpdate()->pluck('id');
            $warehouse->refresh();
            $data = $request->validated();

            if (array_key_exists('is_active', $data) && ! $data['is_active'] && $warehouse->is_active
                && ! Warehouse::where('is_active', true)->whereKeyNot($warehouse->id)->exists()) {
                throw ValidationException::withMessages(['is_active' => 'Minimal satu gudang harus aktif, kalau tidak checkout tidak bisa memilih gudang.']);
            }

            AuditLog::saveWithAudit($warehouse, $data, 'warehouse.updated', $request->user());
        });

        return $this->success('Warehouse updated.', $this->present($warehouse->refresh()));
    }

    /** @return array<string, mixed> */
    private function present(Warehouse $warehouse): array
    {
        return [
            ...$warehouse->only(['id', 'code', 'name', 'city', 'address', 'postal_code', 'latitude', 'longitude', 'phone', 'is_active']),
            // Biteship menolak membuat pengiriman bila salah satu kosong (BiteshipShipmentService).
            'shipping_ready' => filled($warehouse->address) && filled($warehouse->postal_code) && filled($warehouse->phone),
        ];
    }
}
