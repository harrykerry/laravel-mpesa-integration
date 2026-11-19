<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Makes a request safe to send twice.
 *
 * A caller that retries after a timeout would otherwise prompt the customer
 * for payment a second time. When a request carries an Idempotency-Key, the
 * first response is stored and replayed for every repeat of that key, and a
 * repeat arriving while the first is still in flight is refused rather than
 * run alongside it.
 *
 * A request with no key is still protected: an identical payload inside the
 * auto window replays the first response too. Send a key per checkout
 * attempt to make it exact, and set MPESA_IDEMPOTENCY_REQUIRED=true to
 * insist on one.
 */
class PreventDuplicateRequests
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) config('mpesa.security.idempotency.header', 'Idempotency-Key');
        $key = trim((string) $request->header($header, ''));
        $ttl = (int) config('mpesa.security.idempotency.ttl');

        if ($key === '' && config('mpesa.security.idempotency.required')) {
            return ApiResponse::error(
                message: "An {$header} header is required on this endpoint.",
                status: HttpStatus::HTTP_BAD_REQUEST,
                errorCode: 'idempotency.key_missing',
            );
        }

        if ($key === '') {
            $window = (int) config('mpesa.security.idempotency.auto_window');

            // Without a key from the caller, the request itself becomes the
            // key for a short while, so a double-tapped checkout button
            // cannot prompt the same customer twice.
            if ($window < 1) {
                return $next($request);
            }

            $key = 'auto:'.hash('sha256', json_encode($request->all(), JSON_THROW_ON_ERROR));
            $ttl = $window;
        }

        $cacheKey = 'mpesa:idempotency:'.hash('sha256', $request->path().'|'.$key);

        if (is_array($stored = Cache::get($cacheKey))) {
            Log::channel('mpesa')->info('IDEMPOTENT_REPLAY: returning the stored response', [
                'idempotency_key' => $key,
                'path' => $request->path(),
            ]);

            return response()
                ->json($stored['body'], $stored['status'])
                ->header('Idempotency-Replayed', 'true');
        }

        $lock = Cache::lock($cacheKey.':lock', 30);

        if (!$lock->get()) {
            return ApiResponse::error(
                message: 'An identical request is still being processed.',
                status: HttpStatus::HTTP_CONFLICT,
                errorCode: 'idempotency.in_progress',
            );
        }

        try {
            $response = $next($request);

            // Only a settled answer is worth replaying; a server-side error
            // should be retryable.
            if ($response->getStatusCode() < 500) {
                Cache::put($cacheKey, [
                    'status' => $response->getStatusCode(),
                    'body' => json_decode((string) $response->getContent(), true),
                ], $ttl);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }
}
