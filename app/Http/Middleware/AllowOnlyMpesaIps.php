<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Lets only Safaricom's callback IPs reach the callback endpoints.
 *
 * M-PESA cannot send an API key, so the sending address is what identifies
 * it. Addresses come from config('mpesa.security.callback_ips') and CIDR
 * ranges are accepted. Behind a proxy this needs trusted_proxies set, or
 * every callback appears to come from the proxy.
 */
class AllowOnlyMpesaIps
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('mpesa.security.enforce_callback_ips')) {
            return $next($request);
        }

        $allowed = (array) config('mpesa.security.callback_ips', []);
        $ip = (string) $request->ip();

        if ($allowed !== [] && !IpUtils::checkIp($ip, $allowed)) {
            Log::channel('mpesa')->warning('CALLBACK_REFUSED: address is not a Safaricom callback IP', [
                'ip' => $ip,
                'path' => $request->path(),
            ]);

            return ApiResponse::error(
                message: 'This endpoint only accepts callbacks from M-PESA.',
                status: HttpStatus::HTTP_FORBIDDEN,
                errorCode: 'callback.ip_not_allowed',
            );
        }

        return $next($request);
    }
}
