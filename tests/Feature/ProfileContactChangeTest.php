<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\MfaService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Email dan nomor HP staf: berubah hanya dengan password saat ini, dan dengan kode autentikator bila MFA aktif. */
class ProfileContactChangeTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Kata-Sandi-Lama-1';

    private const SECRET = 'GEZDGNBVGY3TQOJQ';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function staff(bool $mfa, array $attributes = []): User
    {
        $user = User::factory()->create([
            'password' => self::PASSWORD,
            'mfa_totp_confirmed_at' => now(),
            'mfa_totp_secret' => $mfa ? self::SECRET : null,
            ...$attributes,
        ]);
        $user->assignRole('marketing');

        return $user;
    }

    private function code(int $offset = 0): string
    {
        return app(MfaService::class)->totp(self::SECRET, (int) floor(now()->getTimestamp() / 30) + $offset);
    }

    /** @param array<string, mixed> $extra */
    private function update(User $user, array $extra = [])
    {
        return $this->actingAs($user)->patchJson('/api/v1/admin/profile', ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, ...$extra]);
    }

    public function test_phone_is_normalized_stored_unverified_and_audited_masked(): void
    {
        $user = $this->staff(false, ['phone_verified_at' => now()]);

        $this->update($user, ['phone' => '0812-3456-7890', 'current_password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.phone', '+6281234567890')
            ->assertJsonPath('data.phone_verified', false);

        $this->assertSame('+6281234567890', $user->fresh()->phone);
        $this->assertNull($user->fresh()->phone_verified_at);
        $log = AuditLog::where('action', 'profile.updated')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('81234567890', json_encode($log->changes), 'nomor utuh tidak masuk Audit Log');
        $this->assertStringContainsString('7890', json_encode($log->changes));
    }

    public function test_changing_phone_needs_the_current_password(): void
    {
        $user = $this->staff(false);

        $this->update($user, ['phone' => '081234567890'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->update($user, ['phone' => '081234567890', 'current_password' => 'salah-total-1A'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertNull($user->fresh()->phone);
    }

    public function test_phone_can_be_removed_and_unchanged_phone_needs_nothing(): void
    {
        $user = $this->staff(true, ['phone' => '+6281234567890']);

        $this->update($user, ['name' => 'Nama Baru'])->assertOk();
        $this->update($user, ['phone' => null, 'current_password' => self::PASSWORD, 'mfa_code' => $this->code()])->assertOk()->assertJsonPath('data.phone', null);
    }

    public function test_phone_format_and_uniqueness_are_enforced(): void
    {
        $user = $this->staff(false);
        User::factory()->create(['phone' => '+6281999999999']);

        $this->update($user, ['phone' => '12345', 'current_password' => self::PASSWORD])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->update($user, ['phone' => '081999999999', 'current_password' => self::PASSWORD])->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_with_mfa_enabled_a_code_is_required_for_email_and_phone(): void
    {
        $user = $this->staff(true);

        $this->update($user, ['email' => 'baru@example.com', 'current_password' => self::PASSWORD])
            ->assertUnprocessable()->assertJsonValidationErrors('mfa_code');
        $this->update($user, ['phone' => '081234567890', 'current_password' => self::PASSWORD])
            ->assertUnprocessable()->assertJsonValidationErrors('mfa_code');
        $this->update($user, ['phone' => '081234567890', 'current_password' => self::PASSWORD, 'mfa_code' => '000000'])
            ->assertUnprocessable()->assertJsonValidationErrors('mfa_code');

        $fresh = $user->fresh();
        $this->assertNull($fresh->phone);
        $this->assertNotSame('baru@example.com', $fresh->email);
    }

    public function test_correct_code_allows_the_change_and_cannot_be_reused(): void
    {
        $user = $this->staff(true);

        $this->update($user, ['email' => 'baru@example.com', 'phone' => '081234567890', 'current_password' => self::PASSWORD, 'mfa_code' => $this->code()])->assertOk();
        $this->assertSame(['baru@example.com', '+6281234567890'], [$user->fresh()->email, $user->fresh()->phone]);

        $this->update($user->fresh(), ['phone' => '081200000000', 'current_password' => self::PASSWORD, 'mfa_code' => $this->code()])
            ->assertUnprocessable()->assertJsonValidationErrors('mfa_code');
        $this->assertSame('+6281234567890', $user->fresh()->phone, 'kode yang sama tidak bisa dipakai dua kali');
    }

    public function test_wrong_password_does_not_consume_the_code(): void
    {
        $user = $this->staff(true);

        $this->update($user, ['phone' => '081234567890', 'current_password' => 'salah-total-1A', 'mfa_code' => $this->code()])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->update($user, ['phone' => '081234567890', 'current_password' => self::PASSWORD, 'mfa_code' => $this->code()])->assertOk();
    }

    public function test_name_change_alone_needs_no_code(): void
    {
        $user = $this->staff(true);

        $this->update($user, ['name' => 'Nama Baru'])->assertOk()->assertJsonPath('data.user.name', 'Nama Baru');
    }

    public function test_profile_show_includes_phone(): void
    {
        $user = $this->staff(false, ['phone' => '+6281234567890', 'phone_verified_at' => now()]);

        $this->actingAs($user)->getJson('/api/v1/admin/profile')->assertOk()->assertJsonPath('data.phone', '+6281234567890')->assertJsonPath('data.phone_verified', true);
    }
}
