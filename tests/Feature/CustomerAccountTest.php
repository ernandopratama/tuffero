<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerAccountTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'sandi-lama-123';

    private function customer(array $attributes = []): User
    {
        return User::factory()->create(['password' => self::PASSWORD, 'phone' => '+628110000009', ...$attributes]);
    }

    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/v1/account/profile')->assertUnauthorized();
        $this->patchJson('/api/v1/account/profile', ['name' => 'X'])->assertUnauthorized();
        $this->putJson('/api/v1/account/profile/password', [])->assertUnauthorized();
    }

    public function test_staff_is_rejected(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = User::factory()->create(['password' => self::PASSWORD])->assignRole('store-admin');

        $this->actingAs($staff)->getJson('/api/v1/account/profile')->assertForbidden();
        $this->actingAs($staff)->patchJson('/api/v1/account/profile', ['name' => 'Baru'])->assertForbidden();
        $this->actingAs($staff)->putJson('/api/v1/account/profile/password', [
            'current_password' => self::PASSWORD,
            'password' => 'sandi-baru-123',
            'password_confirmation' => 'sandi-baru-123',
        ])->assertForbidden();
        $this->assertTrue(Hash::check(self::PASSWORD, $staff->fresh()->password));
    }

    public function test_show_returns_own_data_without_secrets(): void
    {
        $user = $this->customer(['phone_verified_at' => now()]);
        $other = $this->customer(['email' => 'lain@tuffero.test', 'phone' => '+628110000008']);

        $response = $this->actingAs($user)->getJson('/api/v1/account/profile')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.phone', '+628110000009')
            ->assertJsonPath('data.phone_verified', true);

        $this->assertStringNotContainsString($other->email, $response->getContent());
        $this->assertArrayNotHasKey('password', $response->json('data'));
    }

    public function test_update_changes_only_name(): void
    {
        $user = $this->customer();
        $email = $user->email;

        $this->actingAs($user)->patchJson('/api/v1/account/profile', [
            'name' => 'Nama Baru',
            'email' => 'penyerang@tuffero.test',
            'phone' => '+628110000007',
            'phone_verified_at' => now()->toIso8601String(),
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.name', 'Nama Baru');

        $fresh = $user->fresh();
        $this->assertSame('Nama Baru', $fresh->name);
        $this->assertSame($email, $fresh->email);
        $this->assertSame('+628110000009', $fresh->phone);
        $this->assertNull($fresh->phone_verified_at);
        $this->assertTrue($fresh->is_active);
    }

    public function test_update_validates_name(): void
    {
        $user = $this->customer();

        $this->actingAs($user)->patchJson('/api/v1/account/profile', ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'name_required');
        $this->actingAs($user)->patchJson('/api/v1/account/profile', ['name' => str_repeat('a', 121)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'name_too_long');
    }

    #[DataProvider('invalidPasswords')]
    public function test_password_change_rejects_invalid_input(array $payload, string $field, string $code): void
    {
        $user = $this->customer();

        $this->actingAs($user)->putJson('/api/v1/account/profile/password', $payload)
            ->assertUnprocessable()
            ->assertJsonPath("errors.{$field}.0", $code);

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    /** @return array<string, array{array<string, string>, string, string}> */
    public static function invalidPasswords(): array
    {
        return [
            'sandi saat ini salah' => [['current_password' => 'salah', 'password' => 'sandi-baru-123', 'password_confirmation' => 'sandi-baru-123'], 'current_password', 'current_wrong'],
            'terlalu pendek' => [['current_password' => self::PASSWORD, 'password' => 'pendek', 'password_confirmation' => 'pendek'], 'password', 'password_too_short'],
            'konfirmasi beda' => [['current_password' => self::PASSWORD, 'password' => 'sandi-baru-123', 'password_confirmation' => 'sandi-lain-123'], 'password', 'password_mismatch'],
            'sama dengan lama' => [['current_password' => self::PASSWORD, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD], 'password', 'password_same'],
        ];
    }

    public function test_password_change_succeeds_and_ends_other_sessions(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->customer();
        DB::table('sessions')->insert(['id' => 'perangkat-lain', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);

        $this->actingAs($user)->putJson('/api/v1/account/profile/password', [
            'current_password' => self::PASSWORD,
            'password' => 'sandi-baru-123',
            'password_confirmation' => 'sandi-baru-123',
        ])->assertOk();

        $this->assertTrue(Hash::check('sandi-baru-123', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'perangkat-lain']);
    }

    public function test_password_change_is_rate_limited(): void
    {
        $user = $this->customer();
        $payload = ['current_password' => 'salah', 'password' => 'sandi-baru-123', 'password_confirmation' => 'sandi-baru-123'];

        foreach (range(1, 5) as $attempt) {
            $this->actingAs($user)->putJson('/api/v1/account/profile/password', $payload)->assertUnprocessable();
        }

        $this->actingAs($user)->putJson('/api/v1/account/profile/password', $payload)->assertTooManyRequests();
    }
}
