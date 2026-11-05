<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * customer: halaman akun hanya untuk pelanggan; tamu ke /masuk, staf ke /admin.
 * guest: pelanggan atau staf yang sudah masuk tidak perlu melihat formulir masuk atau daftar lagi.
 */
class RedirectForStoreAccount
{
    public function handle(Request $request, Closure $next, string $mode): Response
    {
        $user = $request->user();

        if ($mode === 'guest') {
            return $user ? redirect('/') : $next($request);
        }

        if (! $user) {
            return redirect('/masuk');
        }

        return $user->roles()->exists() ? redirect('/admin') : $next($request);
    }
}
