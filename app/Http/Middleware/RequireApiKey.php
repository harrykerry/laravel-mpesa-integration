<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Requires an API key on the endpoints a frontend or operator calls.
 *
 * The key goes in X-API-Key, or as a bearer token. Keys are configured in
 * MPESA_API_KEYS as a comma separated list, so one can be revoked by
 * removing it. Comparison is constant time.
 *
 * With no keys configured the endpoints stay open outside production, so a
 * fresh clone runs; in production the request is refused.
 */
class RequireApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $keys = (array) config('mpesa.security.api_keys', []);

        if ($keys === []) {
            if (app()->environment('production')) {
                Log::channel('mpesa')->critical('API_KEY_MISSING: no keys configured, request refused');

                return ApiResponse::error(
                    message: 'This API is not accepting requests yet.',
                    status: HttpStatus::HTTP_SERVICE_UNAVAILABLE,
                    errorCode: 'api_key.not_configured',
                );
            }

            return $next($request);
        }

        $presented = (string) ($request->header('X-API-Key') ?? $request->bearerToken() ?? '');

        foreach ($keys as $key) {
            if ($presented !== '' && hash_equals((string) $key, $presented)) {
                return $next($request);
            }
        }

        Log::channel('mpesa')->warning('API_KEY_REJECTED', [
            'ip' => $request->ip(),
            'path' => $request->path(),
            'presented' => $presented === '' ? 'none' : 'invalid',
        ]);

        return ApiResponse::error(
            message: 'A valid API key is required.',
            status: HttpStatus::HTTP_UNAUTHORIZED,
            errorCode: 'api_key.invalid',
        );
    }
}
