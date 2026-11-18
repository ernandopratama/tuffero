<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\NewProductDraftSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Draf Bola Susu dan Bites Fudgy (D-117): aman dilihat admin, tidak bisa terbit sebelum dilengkapi. */
class NewProductDraftSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_unpublished_drafts_once(): void
    {
        $this->seed(NewProductDraftSeeder::class);
        $this->seed(NewProductDraftSeeder::class);

        $this->assertSame(['Bites Fudgy', 'Bola Susu'], Product::orderBy('name')->pluck('name')->all());
        $this->assertSame(0, Product::where('is_published', true)->count());
        $this->assertSame(2, ProductVariant::count());
        $this->assertSame(0, ProductVariant::where('is_active', true)->count());
    }

    public function test_draft_cannot_be_published_until_the_variant_is_activated(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(NewProductDraftSeeder::class);
        $admin = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $admin->assignRole('store-admin');
        $product = Product::where('name', 'Bola Susu')->firstOrFail();

        $this->actingAs($admin)->patchJson("/api/v1/admin/products/{$product->public_id}", ['is_published' => true])->assertJsonValidationErrors('is_published');
        $this->assertFalse($product->fresh()->is_published);
    }
}
