<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Answers only on the domains this deployment owns.
 *
 * The URLs registered with M-PESA point at one host. Requests arriving with
 * any other Host header are not from that registration, and are refused
 * before any payload is recorded. A leading dot allows subdomains, as in
 * ".pay.example.com". Leaving the allowlist empty allows any host.
 */
class AllowOnlyKnownHosts
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = (array) config('mpesa.security.allowed_hosts', []);

        if ($allowed === []) {
            return $next($request);
        }

        $host = strtolower($request->getHost());

        foreach ($allowed as $candidate) {
            $candidate = strtolower(trim((string) $candidate));

            if ($candidate === $host || (str_starts_with($candidate, '.') && str_ends_with($host, $candidate))) {
                return $next($request);
            }
        }

        Log::channel('mpesa')->warning('CALLBACK_REFUSED: host is not allowlisted', [
            'host' => $host,
            'ip' => $request->ip(),
            'path' => $request->path(),
        ]);

        return ApiResponse::error(
            message: 'This host does not serve the M-PESA API.',
            status: HttpStatus::HTTP_FORBIDDEN,
            errorCode: 'host.not_allowed',
        );
    }
}
