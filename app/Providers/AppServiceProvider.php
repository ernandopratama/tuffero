<?php

namespace App\Providers;

use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\PromoContent;
use App\Models\Refund;
use App\Models\Voucher;
use App\Models\Warehouse;
use App\Services\Chat\ChatModel;
use App\Services\Chat\ClaudeChatModel;
use App\Services\Marketing\ClaudeMarketingModel;
use App\Services\Marketing\MarketingModel;
use App\Services\Otp\LogOtpSender;
use App\Services\Otp\MetaWhatsAppOtpSender;
use App\Services\Otp\OtpSender;
use App\Services\Otp\UnavailableOtpSender;
use App\Services\Shipping\BiteshipShippingProvider;
use App\Services\Shipping\ShippingProvider;
use App\Services\Shipping\TestShippingProvider;
use App\Services\Shipping\UnavailableShippingProvider;
use App\Services\Translation\ClaudeTranslator;
use App\Services\Translation\Translator;
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
        $this->app->bind(ShippingProvider::class, fn () => match (true) {
            $this->app->environment('testing') => new TestShippingProvider,
            filled(config('services.biteship.key')) && filled(Warehouse::originPostalCode()) => new BiteshipShippingProvider,
            $this->app->environment('local') => new TestShippingProvider,
            default => new UnavailableShippingProvider,
        });
        $this->app->bind(OtpSender::class, fn () => match (true) {
            $this->app->environment('testing') => new LogOtpSender,
            filled(config('services.whatsapp.token')) && filled(config('services.whatsapp.phone_number_id')) && filled(config('services.whatsapp.otp_template')) => new MetaWhatsAppOtpSender,
            $this->app->environment('local') => new LogOtpSender,
            default => new UnavailableOtpSender,
        });
        $this->app->bind(Translator::class, ClaudeTranslator::class);
        $this->app->bind(ChatModel::class, ClaudeChatModel::class);
        $this->app->bind(MarketingModel::class, ClaudeMarketingModel::class);
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
        Route::model('refund', Refund::class);
        Route::model('cancellation', OrderCancellationRequest::class);

        RateLimiter::for('login', function (Request $request) {
            $login = $request->input('login', $request->input('email'));

            return [
                Limit::perMinute(5)->by(Str::lower(is_string($login) ? trim($login) : '').'|'.$request->ip()),
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

        // Ongkir di chat memanggil penyedia tarif (Biteship kelak), jadi dibatasi per IP.
        // Cek ongkir checkout: 10 per menit per IP dan per sesi (D-075).
        RateLimiter::for('shipping-rates', fn (Request $request) => [
            Limit::perMinute(10)->by('shipping-rates|'.$request->ip()),
            Limit::perMinute(10)->by('shipping-rates-session|'.$request->session()->getId()),
        ]);

        // Cek ongkir di chat terbuka untuk siapa saja dan tiap cek memotong saldo Biteship: 5 per jam per IP (D-076).
        RateLimiter::for('chat-rates', fn (Request $request) => Limit::perHour(5)->by('chat-rates|'.$request->ip()));
        // Jawaban AI di chat memakai kunci Claude berbayar (D-061, D-081): batas per IP per menit dan per hari,
        // ditambah batas harian seluruh toko sebagai rem biaya. Batas belanja bulanan tetap diatur di Claude Console.
        RateLimiter::for('chat-ai', fn (Request $request) => [
            Limit::perMinute(6)->by('chat-ai-minute|'.$request->ip()),
            Limit::perDay(60)->by('chat-ai-day|'.$request->ip()),
            Limit::perDay(3000)->by('chat-ai-store'),
        ]);

        // Pembuat draf konten AI memakai kunci Claude berbayar dan pencarian web (D-079): 5 per menit dan 20 per jam per akun staf.
        // Batas harian seluruh toko ada di MarketingGenerator::DAILY_LIMIT.
        RateLimiter::for('marketing-ai', fn (Request $request) => [
            Limit::perMinute(5)->by('marketing-ai-minute|'.$request->user()?->id),
            Limit::perHour(20)->by('marketing-ai-hour|'.$request->user()?->id),
        ]);

        // Penerima laporan Hermes (D-079): jadwal mingguan hanya butuh beberapa kiriman, jadi batasnya kecil per IP.
        RateLimiter::for('hermes-ingest', fn (Request $request) => [
            Limit::perMinute(10)->by('hermes-ingest-minute|'.$request->ip()),
            Limit::perDay(100)->by('hermes-ingest-day'),
        ]);

        // Batas kasar per permintaan. Batas pengiriman yang berbiaya (per nomor dan IP, D-093) ada di PhoneOtpService.
        RateLimiter::for('phone-otp', fn (Request $request) => Limit::perMinute(20)->by('phone-otp|'.$request->ip()));

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
