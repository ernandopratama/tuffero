<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menjaga jalur Hermes Agent (D-079): token bersama di header `Authorization: Bearer`, bukan sesi.
 * Tanpa token terkonfigurasi (kosong atau kurang dari 32 karakter) jalurnya mati dan menjawab 404, bukan "ditolak",
 * supaya keberadaannya tidak terlihat dari luar. Token salah menjawab 401. Perbandingan dengan `hash_equals`.
 */
class HermesToken
{
    private const MIN_TOKEN_LENGTH = 32;

    public function handle(Request $request, Closure $next): Response
    {
        $token = config('services.hermes.token');

        if (! is_string($token) || strlen($token) < self::MIN_TOKEN_LENGTH) {
            abort(404);
        }

        if (! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
