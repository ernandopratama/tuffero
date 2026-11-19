<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CustomerBlock;
use App\Models\NewsItem;
use App\Models\PromoContent;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SampleSeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_dev_sample_data_is_created_once_and_without_roles_or_real_phone_numbers(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->seed(DevSampleDataSeeder::class);
        $this->seed(DevSampleDataSeeder::class);

        $this->assertSame(4, User::whereDoesntHave('roles')->count());
        $this->assertSame(3, CustomerBlock::count());
        $this->assertSame(1, CustomerBlock::whereNotNull('released_at')->count());
        $this->assertSame(3, Campaign::count());
        $this->assertSame(3, PromoContent::count());
        $this->assertSame(4, NewsItem::count());
        $this->assertSame('youtube', PromoContent::where('type', 'video')->value('provider'));
        // Nomor blokir contoh sama dengan nomor pelanggan contoh yang diblokir.
        $this->assertTrue(CustomerBlock::active()->where('phone_hash', CustomerBlock::phoneHash(User::where('email', 'pelanggan-blokir@tuffero.test')->value('phone')))->exists());
    }

    public function test_sample_data_is_refused_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        // Dipanggil langsung: perintah seed di production meminta konfirmasi interaktif.
        (new DevSampleDataSeeder)->setContainer($this->app)->run();

        $this->assertSame(0, User::count());
        $this->assertSame(0, Campaign::count());
        $this->assertSame(0, NewsItem::count());
        $this->assertSame(0, CustomerBlock::count());
    }
}
