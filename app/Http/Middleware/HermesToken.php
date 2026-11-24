<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menjaga jalur Hermes Agent (D-079): token di header `Authorization: Bearer`, bukan sesi.
 * Tanpa token terkonfigurasi (kosong atau kurang dari 32 karakter) jalurnya mati dan menjawab 404, bukan "ditolak",
 * supaya keberadaannya tidak terlihat dari luar. Token salah menjawab 401. Perbandingan dengan `hash_equals`.
 * Parameter `read` memakai token baca khusus (`HERMES_READ_TOKEN`, D-150): token kiriman draf tidak bisa membaca metrik
 * dan token baca tidak bisa mengirim draf. Tanpa parameter dipakai token kiriman (`HERMES_INGEST_TOKEN`).
 */
class HermesToken
{
    private const MIN_TOKEN_LENGTH = 32;

    public function handle(Request $request, Closure $next, string $scope = 'ingest'): Response
    {
        $token = config($scope === 'read' ? 'services.hermes.read_token' : 'services.hermes.token');

        if (! is_string($token) || strlen($token) < self::MIN_TOKEN_LENGTH) {
            abort(404);
        }

        if (! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
