<?php

namespace App\Services;

use App\Exceptions\MpesaException;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Issues and caches Daraja OAuth access tokens.
 *
 * Credentials come from configuration only. Tokens live for an hour and the
 * token endpoint is rate limited, so a token is cached and shared by every
 * request until shortly before M-PESA expires it.
 */
class MpesaAuthService
{
    /**
     * Get a usable access token, fetching a new one only when needed.
     *
     * @throws MpesaException
     */
    public function accessToken(bool $forceFresh = false): string
    {
        $cache = $this->tokenCache();
        $key = (string) config('mpesa.token.key');

        if ($forceFresh) {
            $cache->forget($key);
        } elseif (filled($token = $cache->get($key))) {
            return (string) $token;
        }

        return $this->withoutConcurrentFetches($key, function () use ($cache, $key): string {
            if (filled($token = $cache->get($key))) {
                return (string) $token;
            }

            ['access_token' => $token, 'expires_in' => $expiresIn] = $this->requestNewToken();

            $cache->put($key, $token, now()->addSeconds($expiresIn));

            return $token;
        });
    }

    /**
     * Forget the cached token, so the next call fetches a new one.
     */
    public function forgetAccessToken(): void
    {
        $this->tokenCache()->forget((string) config('mpesa.token.key'));
    }

    /**
     * Ask Daraja for a new access token.
     *
     * @return array{access_token: string, expires_in: int}
     *
     * @throws MpesaException
     */
    public function requestNewToken(): array
    {
        $key = config('mpesa.credentials.consumer_key');
        $secret = config('mpesa.credentials.consumer_secret');

        if (blank($key) || blank($secret)) {
            throw MpesaException::missingConfiguration('mpesa.credentials');
        }

        try {
            $response = Http::baseUrl(MpesaClient::baseUrl())
                ->withBasicAuth($key, $secret)
                ->timeout((int) config('mpesa.http.timeout'))
                ->connectTimeout((int) config('mpesa.http.connect_timeout'))
                ->retry(
                    (int) config('mpesa.http.retries'),
                    (int) config('mpesa.http.retry_delay'),
                    throw: false,
                )
                ->acceptJson()
                ->get((string) config('mpesa.endpoints.oauth'));
        } catch (ConnectionException $e) {
            throw MpesaException::authenticationFailed('the token endpoint could not be reached', previous: $e);
        }

        if ($response->failed()) {
            Log::channel('mpesa')->error('AUTH_TOKEN_ERROR', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            throw MpesaException::authenticationFailed(
                "the token endpoint returned HTTP {$response->status()}",
                ['status' => $response->status()],
            );
        }

        $token = $response->json('access_token');

        if (!is_string($token) || $token === '') {
            throw MpesaException::authenticationFailed('the token endpoint returned no access token');
        }

        Log::channel('mpesa')->info('AUTH_TOKEN: token issued', [
            'expires_in' => $response->json('expires_in'),
        ]);

        return [
            'access_token' => $token,
            'expires_in' => $this->cacheLifetime((int) $response->json('expires_in', 3600)),
        ];
    }

    /**
     * How long to keep a token, kept short of the lifetime M-PESA reported.
     */
    public function cacheLifetime(int $expiresIn): int
    {
        return max(60, $expiresIn - (int) config('mpesa.token.leeway'));
    }

    /**
     * Run the fetch behind a lock so concurrent requests issue one token.
     *
     * Falls back to fetching directly on cache stores without locks.
     *
     * @template TValue
     *
     * @param  callable(): TValue  $callback
     * @return TValue
     */
    public function withoutConcurrentFetches(string $key, callable $callback): mixed
    {
        $store = $this->tokenCache()->getStore();

        // Not every cache store supports locks; without one, just fetch.
        if (!$store instanceof LockProvider) {
            return $callback();
        }

        try {
            return $store->lock("{$key}:lock", 15)->block(10, $callback);
        } catch (LockTimeoutException) {
            return $callback();
        }
    }

    /**
     * The cache store tokens are kept in.
     */
    public function tokenCache(): CacheRepository
    {
        return Cache::store(config('mpesa.token.store'));
    }
}
