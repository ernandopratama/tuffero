<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\SaveAddressRequest;
use App\Models\CustomerAddress;
use App\Services\CustomerAddressService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alamat selalu dicari lewat relasi akun yang masuk, jadi alamat orang lain menjawab 404.
 */
class AccountAddressController extends Controller
{
    use ApiResponse;

    public function __construct(private CustomerAddressService $addresses) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureCustomer($request);

        return $this->success('Addresses retrieved.', [
            'items' => $request->user()->addresses()->orderByDesc('is_default')->oldest('id')->get()->map($this->present(...))->values(),
        ]);
    }

    public function store(SaveAddressRequest $request): JsonResponse
    {
        $data = $request->safe()->except('is_default');
        $address = $this->addresses->create($request->user(), $data, $request->boolean('is_default'));

        return $this->success('Address saved.', $this->present($address), 201);
    }

    public function update(SaveAddressRequest $request, int $address): JsonResponse
    {
        $data = $request->safe()->except('is_default');
        $model = $this->find($request, $address);
        $this->addresses->update($request->user(), $model, $data, $request->boolean('is_default'));

        return $this->success('Address saved.', $this->present($model));
    }

    public function makeDefault(Request $request, int $address): JsonResponse
    {
        $this->ensureCustomer($request);
        $model = $this->addresses->makeDefault($request->user(), $this->find($request, $address));

        return $this->success('Address saved.', $this->present($model));
    }

    public function destroy(Request $request, int $address): JsonResponse
    {
        $this->ensureCustomer($request);
        $this->addresses->delete($request->user(), $this->find($request, $address));

        return $this->success('Address deleted.');
    }

    private function ensureCustomer(Request $request): void
    {
        abort_if($request->user()->getRoleNames()->isNotEmpty(), 403);
    }

    private function find(Request $request, int $id): CustomerAddress
    {
        return $request->user()->addresses()->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function present(CustomerAddress $address): array
    {
        return $address->only(['id', 'label', 'recipient_name', 'phone', 'street', 'district', 'city', 'province', 'postal_code', 'latitude', 'longitude', 'is_default']);
    }
}
