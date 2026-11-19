<?php

namespace App\Http\Controllers;

use App\Support\PwaApp;
use Illuminate\Http\JsonResponse;

/**
 * Manifest PWA toko dan admin (D-144). 404 saat saklarnya mati, supaya pemasangan baru langsung berhenti.
 * Browser mengambil manifest tanpa cookie, jadi pembatasan "hanya yang sudah masuk" ada di tag link-nya, bukan di sini.
 */
class PwaManifestController extends Controller
{
    public function __invoke(string $app): JsonResponse
    {
        abort_unless(PwaApp::enabled($app), 404);

        return response()
            ->json(PwaApp::manifest($app), 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'no-cache'], JSON_UNESCAPED_SLASHES);
    }
}
