<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pelanggan yang sudah masuk tidak melihat area admin: 404 toko dengan tombol Beranda, bukan kerangka admin "Akses ditolak".
 * Tamu dan staf tetap sampai ke SPA admin; endpoint /api/v1/admin tetap dijaga permission.
 */
class AdminAreaHiddenFromCustomersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    /** @return array<string, array{string}> */
    public static function adminUrls(): array
    {
        return [
            'login' => ['/login'],
            'lupa kata sandi' => ['/lupa-kata-sandi'],
            'beranda admin' => ['/admin'],
            'halaman dalam' => ['/admin/pesanan'],
            'halaman tidak ada' => ['/admin/tidak-ada'],
            'label resi' => ['/admin/label/abc'],
            'forbidden' => ['/admin/forbidden'],
        ];
    }

    #[DataProvider('adminUrls')]
    public function test_a_signed_in_customer_gets_the_store_404_with_a_home_button(string $url): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)->get($url)
            ->assertNotFound()
            ->assertSee('href="/"', false)
            ->assertDontSee('id="app"', false)
            ->assertDontSee('Akses ditolak');
    }

    #[DataProvider('adminUrls')]
    public function test_guests_and_staff_still_reach_the_admin_app(string $url): void
    {
        $this->get($url)->assertOk()->assertSee('id="app"', false);

        $staff = User::factory()->create();
        $staff->assignRole('owner');
        $this->actingAs($staff)->get($url)->assertOk()->assertSee('id="app"', false);
    }

    public function test_customers_are_still_refused_by_the_admin_api(): void
    {
        $customer = User::factory()->create();

        foreach (['/api/v1/admin/dashboard', '/api/v1/admin/orders', '/api/v1/admin/users', '/api/v1/admin/profile'] as $url) {
            $this->actingAs($customer)->getJson($url)->assertForbidden();
        }
    }
}
