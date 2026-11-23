<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * IP pengunjung di belakang proxy: batas OTP, login, dan bukti D-122 memakai Request::ip().
 */
class TrustedProxyTest extends TestCase
{
    private const PROXY = '10.0.0.1';

    private const VISITOR = '203.0.113.9';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_ip', fn (Request $request) => $request->ip());
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    private function ipSeenFrom(string $remote): string
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remote])
            ->withHeaders(['X-Forwarded-For' => self::VISITOR])
            ->get('/_ip')->getContent();
    }

    public function test_forwarded_header_is_ignored_when_no_proxy_is_trusted(): void
    {
        $this->assertSame(self::PROXY, $this->ipSeenFrom(self::PROXY));
    }

    public function test_visitor_ip_is_used_when_the_sender_is_a_trusted_proxy(): void
    {
        TrustProxies::at(self::PROXY);

        $this->assertSame(self::VISITOR, $this->ipSeenFrom(self::PROXY));
    }

    public function test_untrusted_sender_cannot_spoof_its_ip(): void
    {
        TrustProxies::at(self::PROXY);

        $this->assertSame('198.51.100.7', $this->ipSeenFrom('198.51.100.7'));
    }

    public function test_trusted_proxies_are_read_from_config(): void
    {
        config(['app.trusted_proxies' => ['10.0.0.0/8']]);
        $this->app->getProvider(AppServiceProvider::class)->boot();

        $this->assertSame(self::VISITOR, $this->ipSeenFrom(self::PROXY));
    }
}
