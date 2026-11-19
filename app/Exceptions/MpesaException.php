<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Throwable;

/**
 * Raised when a call to Safaricom Daraja cannot be made or does not succeed.
 */
class MpesaException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context  Extra detail for the log, never for the response.
     */
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly array $context = [],
        public readonly int $status = HttpStatus::HTTP_BAD_GATEWAY,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * The application is missing a value it needs before it can call M-PESA.
     */
    public static function missingConfiguration(string $key): self
    {
        return new self(
            message: "M-PESA configuration [{$key}] is not set.",
            errorCode: 'configuration.missing',
            context: ['config_key' => $key],
            status: HttpStatus::HTTP_INTERNAL_SERVER_ERROR,
        );
    }

    /**
     * M-PESA refused to issue an access token.
     *
     * @param  array<string, mixed>  $context
     */
    public static function authenticationFailed(string $reason, array $context = [], ?Throwable $previous = null): self
    {
        return new self(
            message: "M-PESA authentication failed: {$reason}",
            errorCode: 'authentication.failed',
            context: $context,
            previous: $previous,
        );
    }

    /**
     * A Daraja endpoint could not be reached, or answered with a failure.
     *
     * @param  array<string, mixed>  $context
     */
    public static function requestFailed(
        string $endpoint,
        string $reason,
        ?string $errorCode = null,
        array $context = [],
        ?Throwable $previous = null,
    ): self {
        return new self(
            message: "M-PESA request to [{$endpoint}] failed: {$reason}",
            errorCode: $errorCode ?? 'request.failed',
            context: $context + ['endpoint' => $endpoint],
            previous: $previous,
        );
    }

    /**
     * A host outside the allowlist was about to be called.
     */
    public static function disallowedHost(string $host): self
    {
        return new self(
            message: "Refusing to call [{$host}]: the host is not allowlisted.",
            errorCode: 'host.not_allowed',
            context: ['host' => $host],
            status: HttpStatus::HTTP_INTERNAL_SERVER_ERROR,
        );
    }

    /**
     * Detail added to every log entry this exception produces.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return array_filter(['error_code' => $this->errorCode] + $this->context, fn ($value) => $value !== null);
    }

    /**
     * Render the failure without leaking credentials or payloads to the caller.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'error_code' => $this->errorCode,
            'message' => $this->status === HttpStatus::HTTP_INTERNAL_SERVER_ERROR && ! config('app.debug')
                ? 'The M-PESA integration is not configured correctly.'
                : $this->getMessage(),
        ], $this->status);
    }
}
