<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Perubahan sensitif hanya boleh dari akun yang sudah memakai MFA (D-030, D-032).
 */
class EnsureMfaEnrolled
{
    public function handle(Request $request, Closure $next): Response
    {
        // shortcut: akun uji lokal tanpa MFA tetap bisa mencoba menu ini (DemoAccountSeeder); production wajib APP_ENV=production.
        if (! $request->user()?->mfa_totp_confirmed_at && ! app()->environment('local')) {
            return response()->json(['success' => false, 'message' => 'MFA enrollment is required.', 'errors' => []], 403);
        }

        return $next($request);
    }
}
