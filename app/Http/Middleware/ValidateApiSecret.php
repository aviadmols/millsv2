<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the frozen /api and legacy NestJS-compat surface (SYSTEM-MAP §3.1).
 * Accepts the secret from `Authorization: Bearer` or `X-API-Secret`. Empty
 * secret → 503 in production, pass-through in dev (parity with v1).
 */
class ValidateApiSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('api.secret', '');

        if ($secret === '') {
            if (app()->isProduction()) {
                return response()->json(['message' => 'Server misconfigured'], 503);
            }

            return $next($request);
        }

        $token = $request->bearerToken() ?: (string) $request->header('X-API-Secret', '');

        if (! hash_equals($secret, $token)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
