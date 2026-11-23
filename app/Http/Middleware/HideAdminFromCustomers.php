<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Store\StorefrontController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pelanggan yang sudah masuk (akun tanpa role) tidak boleh melihat area admin: /login, /lupa-kata-sandi, dan /admin/* menjawab
 * halaman 404 toko (tombol Beranda ada di halaman itu), bukan kerangka admin dengan "Akses ditolak". Tamu dan staf lewat seperti biasa.
 * Cermin dari RedirectForStoreAccount, yang mengarahkan staf dari halaman akun toko ke /admin. Endpoint /api/v1/admin tetap dijaga permission.
 */
class HideAdminFromCustomers
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->roles()->exists()) {
            return app(SetStoreContext::class)->handle($request, fn (Request $request) => app(StorefrontController::class)->missing($request));
        }

        return $next($request);
    }
}
