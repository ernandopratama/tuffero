<?php

namespace Tests\Concurrency;

use App\Models\Order;
use App\Models\User;
use App\Services\CancellationService;
use App\Services\CartService;
use App\Services\CheckoutService;
use App\Services\InventoryService;
use App\Services\OrderExpiryService;
use App\Services\OrderStatusService;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\QueryException;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Operasi yang dilombakan, memanggil kode produksi apa adanya (CheckoutService, OrderExpiryService, OrderStatusService,
 * CancellationService, InventoryService). prepare() menyiapkan semuanya; closure yang dikembalikannya hanya berisi panggilan
 * yang diuji, jadi waktu mulai antar proses selisih sangat tipis.
 */
final class Actions
{
    /** @param  array<string, mixed>  $args */
    public static function prepare(string $action, array $args): Closure
    {
        switch ($action) {
            case 'checkout':
                return self::checkout($args);
            case 'expire':
                $expiry = app(OrderExpiryService::class);

                return fn () => $expiry->expireDue();
            case 'pay':
                $status = app(OrderStatusService::class);

                return fn () => self::orderRow($status->markPaid(Order::findOrFail($args['order_id'])));
            case 'cancel':
                $cancellation = app(CancellationService::class);

                return fn () => self::orderRow($cancellation->cancelUnpaid(Order::findOrFail($args['order_id'])));
            case 'adjust':
                $inventory = app(InventoryService::class);

                return fn () => $inventory->adjust($args['warehouse_id'], $args['variant_id'], $args['type'], $args['change'], 'Uji konkurensi', User::findOrFail($args['actor_id']))->quantity;
        }

        throw new InvalidArgumentException("Aksi tidak dikenal: {$action}");
    }

    /** Menjalankan closure dan merangkum hasilnya menjadi larik yang bisa dikirim lewat JSON. */
    public static function run(Closure $fire): array
    {
        try {
            return ['type' => 'ok', 'value' => $fire()];
        } catch (ValidationException $e) {
            return ['type' => 'validation', 'errors' => $e->errors()];
        } catch (QueryException $e) {
            return ['type' => 'db', 'sqlstate' => $e->errorInfo[0] ?? null, 'code' => $e->errorInfo[1] ?? null, 'message' => mb_substr($e->getMessage(), 0, 400)];
        } catch (Throwable $e) {
            return ['type' => 'error', 'class' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 400)];
        }
    }

    /** @param  array<string, mixed>  $a */
    private static function checkout(array $a): Closure
    {
        $session = new Store('concurrency', new ArraySessionHandler(120));
        $session->start();
        app()->instance(Session::class, $session);
        app()->forgetInstance(CartService::class);
        app()->forgetInstance(CheckoutService::class);

        $cart = app(CartService::class);
        foreach ($a['items'] as [$sku, $quantity]) {
            $cart->add($sku, $quantity);
        }

        $checkout = app(CheckoutService::class);
        $data = [
            'customer_name' => 'Pembeli Uji',
            'customer_email' => 'uji@example.com',
            'customer_phone' => $a['phone'],
            'address' => ['street' => 'Jl. Uji 1', 'district' => 'Klojen', 'city' => 'Malang', 'province' => 'Jawa Timur', 'postal_code' => '65111'],
            'courier' => 'jnt',
            'service' => 'ez',
            'voucher_code' => $a['voucher_code'] ?? null,
        ];

        return fn () => self::orderRow($checkout->place($data, $a['key'])['order']);
    }

    /** @return array{id: int, number: string, status: string} */
    private static function orderRow(Order $order): array
    {
        return ['id' => $order->id, 'number' => $order->order_number, 'status' => $order->status];
    }
}
