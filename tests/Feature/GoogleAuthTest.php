<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
    }

    private function googleReturns(string $id, string $email, bool $verified = true, string $name = 'Budi Google'): void
    {
        $google = (new SocialiteUser)->setRaw(['email_verified' => $verified])->map(['id' => $id, 'name' => $name, 'email' => $email]);
        $google->user = ['email_verified' => $verified];
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($google);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_button_is_unavailable_without_credentials(): void
    {
        config(['services.google.client_id' => null]);

        $this->get('/auth/google')->assertRedirect('/masuk?google=unavailable');
    }

    public function test_redirects_to_google_when_configured(): void
    {
        $this->get('/auth/google')->assertRedirectContains('accounts.google.com');
    }

    public function test_new_customer_is_created_and_logged_in(): void
    {
        $this->googleReturns('g-123', 'budi@example.com');

        $this->get('/auth/google/callback')->assertRedirect('/akun');

        $user = User::where('email', 'budi@example.com')->firstOrFail();
        $this->assertSame('g-123', $user->google_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->mfa_required);
        $this->assertTrue($user->roles->isEmpty());
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_existing_customer_is_linked_by_email_without_duplicate(): void
    {
        $customer = User::factory()->create(['email' => 'ani@example.com', 'mfa_required' => false]);
        $this->googleReturns('g-456', 'ani@example.com');

        $this->get('/auth/google/callback')->assertRedirect('/akun');

        $this->assertSame(1, User::where('email', 'ani@example.com')->count());
        $this->assertSame('g-456', $customer->fresh()->google_id);
        $this->assertAuthenticatedAs($customer, 'web');
    }

    public function test_staff_account_can_never_log_in_through_google(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = User::factory()->create(['email' => 'staf@example.com'])->assignRole('store-admin');
        $this->googleReturns('g-789', 'staf@example.com');

        $this->get('/auth/google/callback')->assertRedirect('/masuk?google=failed');

        $this->assertGuest('web');
        $this->assertNull($staff->fresh()->google_id);
    }

    public function test_unverified_google_email_is_rejected(): void
    {
        $this->googleReturns('g-1', 'palsu@example.com', verified: false);

        $this->get('/auth/google/callback')->assertRedirect('/masuk?google=failed');

        $this->assertGuest('web');
        $this->assertDatabaseMissing('users', ['email' => 'palsu@example.com']);
    }

    public function test_email_of_account_bound_to_another_google_id_is_not_taken_over(): void
    {
        User::factory()->create(['email' => 'terikat@example.com', 'google_id' => 'g-asli', 'mfa_required' => false]);
        $this->googleReturns('g-penyerang', 'terikat@example.com');

        $this->get('/auth/google/callback')->assertRedirect('/masuk?google=failed');

        $this->assertGuest('web');
    }

    public function test_inactive_customer_is_rejected(): void
    {
        User::factory()->create(['email' => 'nonaktif@example.com', 'is_active' => false, 'mfa_required' => false]);
        $this->googleReturns('g-2', 'nonaktif@example.com');

        $this->get('/auth/google/callback')->assertRedirect('/masuk?google=failed');

        $this->assertGuest('web');
    }

    public function test_denied_consent_and_provider_errors_fail_safely(): void
    {
        $this->get('/auth/google/callback?error=access_denied')->assertRedirect('/masuk?google=failed');

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andThrow(new \RuntimeException('bad state'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('/auth/google/callback')->assertRedirect('/masuk?google=failed');
        $this->assertGuest('web');
    }

    public function test_login_page_exposes_google_flag_and_failure_status(): void
    {
        $this->get('/masuk?google=failed')->assertOk()->assertSee('googleStatus', false)->assertSee('failed');
    }
}
