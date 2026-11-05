<?php

namespace Tests\Feature;

use App\Models\CustomerAddress;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAddressTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $overrides */
    private function body(array $overrides = []): array
    {
        return [
            'label' => 'Rumah',
            'recipient_name' => 'Budi Uji',
            'phone' => '0812-3456-7890',
            'street' => 'Jl. Uji 1',
            'district' => 'Klojen',
            'city' => 'Malang',
            'province' => 'Jawa Timur',
            'postal_code' => '65111',
            ...$overrides,
        ];
    }

    private function address(User $user, array $overrides = []): CustomerAddress
    {
        return CustomerAddress::find($this->actingAs($user)->postJson('/api/v1/account/addresses', $this->body($overrides))->assertCreated()->json('data.id'));
    }

    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/v1/account/addresses')->assertUnauthorized();
        $this->postJson('/api/v1/account/addresses', $this->body())->assertUnauthorized();
    }

    public function test_staff_is_rejected(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = User::factory()->create()->assignRole('store-admin');

        $this->actingAs($staff)->getJson('/api/v1/account/addresses')->assertForbidden();
        $this->actingAs($staff)->postJson('/api/v1/account/addresses', $this->body())->assertForbidden();
    }

    public function test_store_normalizes_phone_and_first_address_is_default(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/account/addresses', $this->body())
            ->assertCreated()
            ->assertJsonPath('data.phone', '+6281234567890')
            ->assertJsonPath('data.is_default', true);
    }

    public function test_store_ignores_foreign_user_id(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create();

        $address = $this->address($user, ['user_id' => $victim->id]);

        $this->assertSame($user->id, $address->user_id);
        $this->assertSame(0, $victim->addresses()->count());
    }

    public function test_list_shows_only_own_addresses(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->address($user);
        $this->address($other, ['street' => 'Jl. Rahasia 9']);

        $response = $this->actingAs($user)->getJson('/api/v1/account/addresses')->assertOk()->assertJsonCount(1, 'data.items');

        $this->assertStringNotContainsString('Jl. Rahasia 9', $response->getContent());
    }

    public function test_cannot_touch_other_customers_address(): void
    {
        $user = User::factory()->create();
        $theirs = $this->address(User::factory()->create(), ['street' => 'Jl. Rahasia 9']);

        $this->actingAs($user)->patchJson("/api/v1/account/addresses/{$theirs->id}", $this->body(['street' => 'Diubah']))->assertNotFound();
        $this->actingAs($user)->postJson("/api/v1/account/addresses/{$theirs->id}/default")->assertNotFound();
        $this->actingAs($user)->deleteJson("/api/v1/account/addresses/{$theirs->id}")->assertNotFound();

        $this->assertSame('Jl. Rahasia 9', $theirs->fresh()->street);
    }

    public function test_only_one_default_address(): void
    {
        $user = User::factory()->create();
        $first = $this->address($user);
        $second = $this->address($user, ['label' => 'Kantor', 'is_default' => true]);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);

        $this->actingAs($user)->postJson("/api/v1/account/addresses/{$first->id}/default")->assertOk();

        $this->assertTrue($first->fresh()->is_default);
        $this->assertFalse($second->fresh()->is_default);
    }

    public function test_deleting_default_promotes_oldest_remaining(): void
    {
        $user = User::factory()->create();
        $first = $this->address($user);
        $second = $this->address($user, ['label' => 'Kantor']);

        $this->actingAs($user)->deleteJson("/api/v1/account/addresses/{$first->id}")->assertOk();

        $this->assertModelMissing($first);
        $this->assertTrue($second->fresh()->is_default);
    }

    public function test_limit_is_enforced(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 10) as $number) {
            $this->address($user, ['label' => "Alamat {$number}"]);
        }

        $this->actingAs($user)->postJson('/api/v1/account/addresses', $this->body())
            ->assertUnprocessable()
            ->assertJsonPath('errors.address.0', 'limit_reached');
    }

    public function test_validation_codes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/account/addresses', $this->body(['label' => '', 'phone' => '123', 'postal_code' => 'abc']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.label.0', 'label_required')
            ->assertJsonPath('errors.phone.0', 'phone_invalid')
            ->assertJsonPath('errors.postal_code.0', 'postal_invalid');
    }

    public function test_addresses_are_deleted_with_account(): void
    {
        $user = User::factory()->create();
        $address = $this->address($user);

        $user->delete();

        $this->assertModelMissing($address);
    }
}
