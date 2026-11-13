<?php

namespace Tests\Unit;

use App\Models\Warehouse;
use App\Services\Shipping\BiteshipShippingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BiteshipShippingProviderTest extends TestCase
{
    // Kode pos asal dibaca dari tabel warehouses (cadangan: env), jadi tes ini butuh database.
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.biteship.key' => 'biteship_test.dummy',
            'services.biteship.base_url' => 'https://api.biteship.com',
            'services.biteship.origin_postal_code' => '65111',
        ]);
    }

    public function test_maps_biteship_pricing_to_rates(): void
    {
        Http::fake(['api.biteship.com/v1/rates/couriers' => Http::response([
            'success' => true,
            'pricing' => [
                ['courier_name' => 'J&T', 'courier_code' => 'jnt', 'courier_service_name' => 'EZ', 'courier_service_code' => 'ez', 'price' => 15000, 'shipment_duration_range' => '1 - 2', 'shipment_duration_unit' => 'hours'],
                ['courier_name' => 'SiCepat', 'courier_code' => 'sicepat', 'courier_service_name' => 'Reguler', 'courier_service_code' => 'reg', 'price' => 14000],
                ['courier_code' => 'broken'],
            ],
        ])]);

        $rates = (new BiteshipShippingProvider)->rates(['postal_code' => '12440'], 1500);

        $this->assertSame([
            ['courier' => 'jnt', 'service' => 'ez', 'label' => 'J&T EZ', 'fee' => 15000, 'etd' => '1 - 2', 'etd_unit' => 'hours'],
            ['courier' => 'sicepat', 'service' => 'reg', 'label' => 'SiCepat Reguler', 'fee' => 14000, 'etd' => '', 'etd_unit' => 'days'],
        ], $rates);

        Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['biteship_test.dummy']
            && $request['origin_postal_code'] === 65111
            && $request['destination_postal_code'] === 12440
            && $request['couriers'] === 'jnt,sicepat'
            && $request['items'][0]['weight'] === 1500);
    }

    public function test_origin_postal_code_comes_from_the_active_warehouse_before_the_env_fallback(): void
    {
        Warehouse::create(['code' => 'MLG', 'name' => 'Gudang Malang', 'city' => 'Malang', 'postal_code' => '65141', 'is_active' => true]);
        Http::fake(['api.biteship.com/v1/rates/couriers' => Http::response(['success' => true, 'pricing' => []])]);

        (new BiteshipShippingProvider)->rates(['postal_code' => '12440'], 1000);

        Http::assertSent(fn (Request $request) => $request['origin_postal_code'] === 65141);
    }

    public function test_biteship_error_means_shipping_unavailable(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'error' => 'Invalid key'], 401)]);

        try {
            (new BiteshipShippingProvider)->rates(['postal_code' => '12440'], 1000);
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['shipping' => ['shipping_unavailable']], $e->errors());
        }
    }
}
