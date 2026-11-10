<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\CheckoutRequest;
use App\Services\CartService;
use App\Services\CheckoutService;
use App\Services\Shipping\ShippingProvider;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    use ApiResponse;

    public function rates(Request $request, CartService $cart, ShippingProvider $shipping): JsonResponse
    {
        $address = $request->validate([
            'postal_code' => ['required', 'string', 'regex:/^\d{5}$/'],
        ], ['postal_code.required' => 'postal_invalid', 'postal_code.regex' => 'postal_invalid']);

        $weight = $cart->summary()['weight_gram'];

        if ($weight < 1) {
            throw ValidationException::withMessages(['cart' => 'empty']);
        }

        return $this->success('Rates retrieved.', ['rates' => $shipping->rates($address, $weight)]);
    }

    public function store(CheckoutRequest $request, CheckoutService $checkout): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key');

        if (! preg_match('/^[A-Za-z0-9-]{16,64}$/', $key)) {
            return $this->error('Idempotency-Key is required.', [], 400);
        }

        $data = $request->validated();
        $user = $request->user();
        // Hanya dari session, tidak pernah dari body. Pesanan staf bukan pesanan pelanggan.
        $data['user_id'] = $user?->getRoleNames()->isEmpty() ? $user->id : null;
        $data['address'] = [...$data['address'], 'recipient' => $data['customer_name'], 'phone' => $data['customer_phone']];
        // Titik peta disimpan sebagai angka 7 desimal (sekitar 1 cm), atau tidak disimpan sama sekali.
        foreach (['latitude', 'longitude'] as $field) {
            if (isset($data['address'][$field])) {
                $data['address'][$field] = round((float) $data['address'][$field], 7);
            } else {
                unset($data['address'][$field]);
            }
        }

        ['order' => $order, 'token' => $token] = $checkout->place($data, $key);

        // Kiriman ulang tanpa hak akses tidak boleh membuka pesanan orang lain.
        if (! $token && ! $request->session()->get("order_access.{$order->public_ref}")) {
            return $this->error('This request was already used.', [], 409);
        }

        $request->session()->put("order_access.{$order->public_ref}", true);

        return $this->success('Order placed.', ['public_ref' => $order->public_ref, 'token' => $token], $token ? 201 : 200);
    }
}
