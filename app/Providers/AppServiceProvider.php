<?php

namespace App\Providers;

use App\Models\Order;
use App\Models\PromoContent;
use App\Models\Voucher;
use App\Services\Shipping\ShippingProvider;
use App\Services\Shipping\TestShippingProvider;
use App\Services\Shipping\UnavailableShippingProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ShippingProvider::class, fn () => $this->app->environment('local', 'testing')
            ? new TestShippingProvider
            : new UnavailableShippingProvider);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // CommentController dipakai dua jenis target, jadi parameternya tidak diberi tipe.
        Route::model('voucher', Voucher::class);
        Route::model('content', PromoContent::class);
        Route::model('order', Order::class);

        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');

            return [
                Limit::perMinute(5)->by(Str::lower(is_string($email) ? $email : '').'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Nomor pesanan dikunci sendiri supaya menebak email atau HP dari banyak IP tetap terbatas.
        RateLimiter::for('order-lookup', function (Request $request) {
            $number = $request->input('order_number');

            $key = is_string($number) ? Str::upper($number) : '';

            return [
                Limit::perMinutes(15, 5)->by("pair|{$key}|".$request->ip()),
                Limit::perMinutes(15, 20)->by("number|{$key}"),
                Limit::perMinute(10)->by('ip|'.$request->ip()),
            ];
        });

        // Ubah profil dan password butuh password saat ini; dibatasi per akun supaya sesi yang dibajak tidak bisa menebaknya.
        RateLimiter::for('profile', fn (Request $request) => Limit::perMinute(5)->by('profile|'.($request->user()?->id ?: $request->ip())));

        // Dikunci per akun, bukan per IP, supaya menebak kode dari banyak IP tetap terbatas.
        RateLimiter::for('mfa', function (Request $request) {
            $key = $request->session()->get('mfa.pending_user_id') ?: $request->ip();

            return [
                Limit::perMinute(5)->by('minute|'.$key),
                Limit::perHour(30)->by('hour|'.$key),
            ];
        });
    }
}
