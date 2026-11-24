<?php

namespace App\Http\Middleware;

use App\Services\CartService;
use App\Support\PwaApp;
use App\Support\StoreSite;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bahasa dan data bersama halaman toko. Bahasa: ?lang= menang atas cookie pilihan, default Indonesia (D-036).
 * Admin tidak melewati middleware ini.
 */
class SetStoreContext
{
    public const COOKIE = 'tuffero_locale';

    private const LOCALES = ['id', 'en'];

    public function __construct(private StoreSite $site, private CartService $cart) {}

    public function handle(Request $request, Closure $next): Response
    {
        // R-07: /index.php dan /index.php/... memuat halaman yang sama dengan alamat tanpanya. Dibaca dari URI mentah,
        // bukan dari base URL, supaya server yang menulis ulang ke index.php tidak ikut terhitung (tanpa risiko putaran).
        if ($request->isMethodSafe() && preg_match('#^/index\.php(?=[/?]|$)#', $request->getRequestUri())) {
            $rest = substr($request->getRequestUri(), strlen('/index.php'));

            return redirect()->to($request->getSchemeAndHttpHost().($rest === '' || $rest[0] === '?' ? '/'.$rest : $rest), 301);
        }

        $locale = collect([$request->query('lang'), $request->cookie(self::COOKIE)])
            ->first(fn ($value) => in_array($value, self::LOCALES, true)) ?? 'id';

        App::setLocale($locale);

        // Sama seperti EnsureUserIsActive: akun nonaktif keluar dari sesinya.
        if ($request->user() && ! $request->user()->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $user = $request->user();

        View::share([
            'locale' => $locale,
            'locales' => self::LOCALES,
            't' => trans('store.landing'),
            'pg' => trans('store.pages'),
            'site' => $this->site,
            'storeUser' => $user,
            'storeRole' => $user?->getRoleNames()->first(),
            'cartCount' => $this->cart->count(),
            // D-144: manifest dan tombol pasang aplikasi hanya untuk pelanggan yang sudah masuk.
            'pwaStore' => PwaApp::forCustomer($user),
        ]);

        return $next($request);
    }
}
