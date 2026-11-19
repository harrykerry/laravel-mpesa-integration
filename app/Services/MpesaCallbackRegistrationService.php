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
            'ShortCode' => (string) ($overrides['shortcode'] ?? config('mpesa.c2b.shortcode')),
            'ResponseType' => (string) ($overrides['response_type'] ?? config('mpesa.c2b.response_type')),
            'ConfirmationURL' => (string) ($overrides['confirmation_url'] ?? config('mpesa.callbacks.confirmation_url')),
            'ValidationURL' => (string) ($overrides['validation_url'] ?? config('mpesa.callbacks.validation_url')),
        ]);
    }
}
