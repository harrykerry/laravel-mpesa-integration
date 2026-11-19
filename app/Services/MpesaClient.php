<?php

namespace App\Services;

use App\Exceptions\MpesaException;
use App\Support\HostGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The single way out to Safaricom Daraja.
 *
 * Every call is authenticated with a cached access token, bounded by the
 * configured timeouts, retried only when retrying is safe, logged on the
 * M-PESA channel with secrets redacted, and turned into a MpesaException
 * when it does not succeed.
 */
class MpesaClient
{
    /**
     * Request fields that must never reach a log.
     */
    protected const REDACTED = [
        'Password',
        'SecurityCredential',
        'InitiatorName',
        'access_token',
        'Authorization',
    ];

    public function __construct(protected readonly MpesaAuthService $auth) {}

    /**
     * Post a payload to a named Daraja endpoint and return the decoded body.
     *
     * @param  string  $endpoint  A key from config('mpesa.endpoints')
     * @param  array<string, mixed>  $payload
     * @param  bool  $failOnNonZeroCode  False to return M-PESA's own failure code instead of raising.
     * @return array<string, mixed>
     *
     * @throws MpesaException
     */
    public function postToMpesa(string $endpoint, array $payload, bool $failOnNonZeroCode = true): array
    {
        $path = $this->endpointPath($endpoint);
        $reference = (string) Str::uuid();

        Log::channel('mpesa')->info("MPESA_REQUEST: {$endpoint}", [
            'reference' => $reference,
            'path' => $path,
            'payload' => $this->redactSecrets($payload),
        ]);

        $response = $this->sendRequest($endpoint, fn (PendingRequest $request) => $request->post($path, $payload));

        Log::channel('mpesa')->info("MPESA_RESPONSE: {$endpoint}", [
            'reference' => $reference,
            'status' => $response->status(),
            'body' => $this->redactSecrets((array) $response->json()),
        ]);

        return $this->decodeResponse($endpoint, $response, $failOnNonZeroCode);
    }

    /**
     * Get a named Daraja endpoint and return the decoded body.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws MpesaException
     */
    public function getFromMpesa(string $endpoint, array $query = []): array
    {
        $path = $this->endpointPath($endpoint);

        $response = $this->sendRequest($endpoint, fn (PendingRequest $request) => $request->get($path, $query));

        Log::channel('mpesa')->info("MPESA_RESPONSE: {$endpoint}", [
            'status' => $response->status(),
            'body' => $this->redactSecrets((array) $response->json()),
        ]);

        return $this->decodeResponse($endpoint, $response);
    }

    /**
     * The base URL of the active Daraja environment.
     *
     * @throws MpesaException
     */
    public static function baseUrl(): string
    {
        $environment = (string) config('mpesa.environment');
        $baseUrl = config("mpesa.base_urls.{$environment}");

        if (blank($baseUrl)) {
            throw MpesaException::missingConfiguration("mpesa.base_urls.{$environment}");
        }

        $baseUrl = rtrim((string) $baseUrl, '/');

        HostGuard::assertAllowed($baseUrl);

        return $baseUrl;
    }

    /**
     * The absolute URL of a named endpoint.
     *
     * @throws MpesaException
     */
    public function endpointUrl(string $endpoint): string
    {
        return self::baseUrl().'/'.$this->endpointPath($endpoint);
    }

    /**
     * Send a request, turning transport failures into MpesaException.
     *
     * @param  callable(PendingRequest): Response  $send
     *
     * @throws MpesaException
     */
    public function sendRequest(string $endpoint, callable $send): Response
    {
        try {
            return $send($this->pendingRequest());
        } catch (ConnectionException $e) {
            throw MpesaException::requestFailed(
                $endpoint,
                'the endpoint could not be reached',
                'request.unreachable',
                previous: $e,
            );
        } catch (RequestException $e) {
            throw MpesaException::requestFailed($endpoint, $e->getMessage(), previous: $e);
        }
    }

    /**
     * A pending request carrying the token, timeouts and retry policy.
     *
     * @throws MpesaException
     */
    public function pendingRequest(): PendingRequest
    {
        return Http::baseUrl(self::baseUrl())
            ->withToken($this->auth->accessToken())
            ->timeout((int) config('mpesa.http.timeout'))
            ->connectTimeout((int) config('mpesa.http.connect_timeout'))
            ->acceptJson()
            ->asJson()
            ->retry(
                (int) config('mpesa.http.retries'),
                (int) config('mpesa.http.retry_delay'),
                // Retry only what is safe to repeat: a connection that never
                // landed, a throttled request, or a Safaricom-side failure.
                when: fn (\Throwable $e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503, 504], true)),
                throw: false,
            );
    }

    /**
     * Read the response body, failing on anything that is not a success.
     *
     * @return array<string, mixed>
     *
     * @throws MpesaException
     */
    public function decodeResponse(string $endpoint, Response $response, bool $failOnNonZeroCode = true): array
    {
        $body = (array) $response->json();

        if ($response->failed()) {
            Log::channel('mpesa')->error("MPESA_ERROR: {$endpoint}", [
                'status' => $response->status(),
                'body' => $this->redactSecrets($body) ?: $response->body(),
            ]);

            throw MpesaException::requestFailed(
                $endpoint,
                (string) ($body['errorMessage'] ?? "HTTP {$response->status()}"),
                isset($body['errorCode']) ? (string) $body['errorCode'] : null,
                ['status' => $response->status(), 'request_id' => $body['requestId'] ?? null],
            );
        }

        // Daraja answers 200 with a non-zero code when it rejects a request.
        // A status query is the exception: there, a non-zero code is the
        // answer the caller asked for.
        // Success is zero, which Daraja writes as "0" on most products and
        // as "00" on the QR API.
        $code = (string) ($body['ResponseCode'] ?? $body['ResultCode'] ?? '0');
        $succeeded = $code !== '' && ltrim($code, '0') === '';

        if ($failOnNonZeroCode && !$succeeded) {
            throw MpesaException::requestFailed(
                $endpoint,
                (string) ($body['ResponseDescription'] ?? $body['ResultDesc'] ?? "M-PESA returned code {$code}"),
                (string) $code,
                ['body' => $this->redactSecrets($body)],
            );
        }

        return $body;
    }

    /**
     * Resolve a configured endpoint key to its path.
     *
     * @throws MpesaException
     */
    public function endpointPath(string $endpoint): string
    {
        $path = config("mpesa.endpoints.{$endpoint}");

        if (blank($path)) {
            throw MpesaException::missingConfiguration("mpesa.endpoints.{$endpoint}");
        }

        return ltrim((string) $path, '/');
    }

    /**
     * Replace secrets with a placeholder before anything is logged.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redactSecrets(array $payload): array
    {
        foreach (self::REDACTED as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = '[redacted]';
            }
        }

        return $payload;
    }
}
