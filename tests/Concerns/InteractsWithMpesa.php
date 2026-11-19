<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Test helpers for a fully configured, faked M-PESA integration.
 */
trait InteractsWithMpesa
{
    /**
     * One of Safaricom's published callback addresses.
     */
    public const MPESA_IP = '196.201.214.200';

    /**
     * Send the next request from an address M-PESA calls back from.
     */
    protected function fromMpesa(): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::MPESA_IP]);
    }

    /**
     * Configure the integration with sandbox values the tests can assert on.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function configureMpesa(array $overrides = []): void
    {
        config()->set(array_merge([
            'mpesa.environment' => 'sandbox',
            'mpesa.base_urls.sandbox' => 'https://sandbox.safaricom.co.ke',
            'mpesa.credentials.consumer_key' => 'test-key',
            'mpesa.credentials.consumer_secret' => 'test-secret',
            'mpesa.c2b.shortcode' => '600000',
            'mpesa.stk.shortcode' => '174379',
            'mpesa.stk.passkey' => 'test-passkey',
            'mpesa.initiator.name' => 'testapi',
            'mpesa.initiator.security_credential' => 'test-credential',
            'mpesa.payouts.shortcode' => '600000',
            'mpesa.callbacks.confirmation_url' => 'https://example.test/api/v1/mpesa/c2b/confirmation',
            'mpesa.callbacks.validation_url' => 'https://example.test/api/v1/mpesa/c2b/validation',
            'mpesa.callbacks.stk_url' => 'https://example.test/api/v1/mpesa/stk/callback',
            'mpesa.callbacks.result_url' => 'https://example.test/api/v1/mpesa/results/result',
            'mpesa.callbacks.timeout_url' => 'https://example.test/api/v1/mpesa/results/timeout',
            'mpesa.http.retries' => 1,
            'mpesa.http.retry_delay' => 0,
            'mpesa.security.callback_ips' => [self::MPESA_IP],
            'mpesa.security.enforce_callback_ips' => true,
            'mpesa.security.allowed_outbound_hosts' => ['sandbox.safaricom.co.ke', 'api.safaricom.co.ke'],
        ], $overrides));

        Cache::forget((string) config('mpesa.token.key'));
    }

    /**
     * Fake the token endpoint, so any call can authenticate.
     */
    protected function fakeAccessToken(string $token = 'test-access-token', int $expiresIn = 3599): void
    {
        Http::fake([
            $this->tokenUrl() => Http::response([
                'access_token' => $token,
                'expires_in' => (string) $expiresIn,
            ]),
        ]);
    }

    /**
     * Fake the token endpoint plus one Daraja endpoint.
     *
     * @param  array<string, mixed>  $body
     */
    protected function fakeMpesa(string $endpoint, array $body, int $status = 200): void
    {
        Http::fake([
            $this->tokenUrl() => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => '3599',
            ]),
            $this->endpointUrl($endpoint) => Http::response($body, $status),
        ]);
    }

    /**
     * The URL pattern matching the token endpoint.
     */
    protected function tokenUrl(): string
    {
        return rtrim((string) config('mpesa.base_urls.'.config('mpesa.environment')), '/').'/oauth/*';
    }

    /**
     * The URL pattern matching a named Daraja endpoint.
     */
    protected function endpointUrl(string $endpoint): string
    {
        $base = rtrim((string) config('mpesa.base_urls.'.config('mpesa.environment')), '/');

        return $base.'/'.ltrim((string) config("mpesa.endpoints.{$endpoint}"), '/');
    }
}
