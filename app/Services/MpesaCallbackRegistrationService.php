<?php

namespace App\Services;

use App\Exceptions\MpesaException;

/**
 * Registers this application's C2B confirmation and validation URLs with M-PESA.
 */
class MpesaCallbackRegistrationService
{
    public function __construct(protected readonly MpesaClient $client) {}

    /**
     * Register the callback URLs for a shortcode.
     *
     * Everything defaults to configuration; a caller may only override the
     * shortcode, the two URLs and the response type.
     *
     * @param  array{shortcode?: int|string|null, confirmation_url?: string|null, validation_url?: string|null, response_type?: string|null}  $overrides
     * @return array<string, mixed>
     *
     * @throws MpesaException
     */
    public function registerUrls(array $overrides = []): array
    {
        return $this->client->post('register_url', [
            'ShortCode' => $this->required($overrides['shortcode'] ?? config('mpesa.c2b.shortcode'), 'mpesa.c2b.shortcode'),
            'ResponseType' => $overrides['response_type'] ?? config('mpesa.c2b.response_type'),
            'ConfirmationURL' => $this->required(
                $overrides['confirmation_url'] ?? config('mpesa.callbacks.confirmation_url'),
                'mpesa.callbacks.confirmation_url',
            ),
            'ValidationURL' => $this->required(
                $overrides['validation_url'] ?? config('mpesa.callbacks.validation_url'),
                'mpesa.callbacks.validation_url',
            ),
        ]);
    }

    /**
     * Fail loudly when a value M-PESA needs has not been configured.
     *
     * @throws MpesaException
     */
    protected function required(mixed $value, string $key): string
    {
        if (blank($value)) {
            throw MpesaException::missingConfiguration($key);
        }

        return (string) $value;
    }
}
