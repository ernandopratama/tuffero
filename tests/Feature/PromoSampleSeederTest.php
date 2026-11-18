<?php

namespace Tests\Feature;

use App\Models\ProductVariant;
use App\Models\VariantDiscount;
use App\Models\Voucher;
use App\Services\DiscountService;
use Database\Seeders\CatalogDraftSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PromoSampleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Seeder contoh voucher dan diskon varian: opsional, aman diulang, dan angkanya lolos aturan validasi dashboard. */
class PromoSampleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_sample_vouchers_and_discounts_that_respect_the_dashboard_rules(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $this->seed(CatalogDraftSeeder::class);
        $this->seed(PromoSampleSeeder::class);

        $this->assertSame(6, Voucher::count());
        $this->assertSame(3, VariantDiscount::count());

        $shipping = Voucher::where('code', 'ONGKIR10K')->firstOrFail();
        $this->assertTrue($shipping->isShipping());
        $this->assertSame('member', $shipping->audience);
        $this->assertGreaterThanOrEqual(Voucher::SHIPPING_MIN, $shipping->value);
        $this->assertLessThanOrEqual(Voucher::SHIPPING_MAX, $shipping->value);

        $this->assertSame(0, Voucher::where('signup_grant', true)->count(), 'hadiah pendaftaran tidak dibuat oleh seeder contoh');
        $this->assertSame(0, Voucher::where('audience', 'guest')->where('requires_claim', true)->count(), 'voucher klaim butuh akun');
        $this->assertTrue(Voucher::where('code', 'MEMBER20')->value('requires_claim'));
        $this->assertTrue(Voucher::where('code', 'BARENG5K')->value('stackable'));
        $this->assertTrue(Voucher::all()->every(fn (Voucher $v) => $v->is_active && $v->ends_at->isFuture() && ($v->type !== 'percent' || $v->value <= 100)));

        $cap = app(DiscountService::class)->maxPercent();
        foreach (VariantDiscount::with('variant')->get() as $discount) {
            $limit = $discount->type === 'percent' ? $cap : intdiv($discount->variant->price * $cap, 100);
            $this->assertLessThanOrEqual($limit, $discount->value, "diskon {$discount->variant->sku} melewati batas Pengaturan > Diskon");
        }
    }

    public function test_it_can_run_again_without_duplicates_or_overwriting_dashboard_edits(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $this->seed(CatalogDraftSeeder::class);
        $this->seed(PromoSampleSeeder::class);

        Voucher::where('code', 'HEMAT10')->update(['value' => 12, 'is_active' => false]);
        $this->seed(PromoSampleSeeder::class);

        $this->assertSame(6, Voucher::count());
        $this->assertSame(3, VariantDiscount::count());
        $this->assertSame([12, false], [Voucher::where('code', 'HEMAT10')->value('value'), Voucher::where('code', 'HEMAT10')->value('is_active')]);
    }

    public function test_discounts_are_skipped_when_the_catalog_is_not_seeded_and_the_default_seeding_leaves_promos_alone(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(0, Voucher::count());
        $this->assertSame(0, VariantDiscount::count());

        $this->seed(PromoSampleSeeder::class);

        $this->assertSame(6, Voucher::count());
        $this->assertSame(0, VariantDiscount::count());
        $this->assertSame(0, ProductVariant::count());
    }
}
