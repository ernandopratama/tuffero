<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTablePageSizeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(DatabaseSeeder::class);
        User::factory()->count(14)->create()->each->assignRole('marketing');

        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole('super-admin');

        return $user;
    }

    public function test_per_page_limits_rows_to_the_allowed_choices(): void
    {
        $this->actingAs($this->admin());

        $this->getJson('/api/v1/admin/users?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data.items');
    }

    public function test_per_page_outside_the_choices_falls_back_to_the_default(): void
    {
        $this->actingAs($this->admin());

        $total = User::has('roles')->count();
        $this->assertLessThan(25, $total);

        // 7 dan 1000 bukan pilihan (10, 25, 50, 75, 100), jadi diabaikan dan semua baris muat di satu halaman.
        foreach ([7, 1000] as $invalid) {
            $this->getJson("/api/v1/admin/users?per_page={$invalid}")
                ->assertOk()
                ->assertJsonCount($total, 'data.items');
        }
    }
}
