<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerLoginTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        $user = User::factory()->create(['email' => 'pelanggan@example.com', 'password' => 'Rahasia123!']);
        $user->forceFill(['phone' => '+6281234567890', 'mfa_required' => false, 'is_active' => true])->save();

        return $user;
    }

    public function test_customer_logs_in_with_email_or_phone_in_any_environment(): void
    {
        $this->customer();

        foreach (['pelanggan@example.com', '081234567890', '+6281234567890'] as $login) {
            $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'Rahasia123!'])->assertOk();
            $this->app['auth']->guard('web')->logout();
        }
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->customer();

        $this->postJson('/api/v1/auth/login', ['login' => '081234567890', 'password' => 'salah'])->assertStatus(401);
    }
}
