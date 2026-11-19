<?php

namespace App\Services;

use App\Exceptions\MpesaException;

/**
 * Registers this application's C2B confirmation and validation URLs with M-PESA.
 *
 * Nobody initiates a C2B payment: a customer pays the shortcode directly and
 * Safaricom calls these URLs afterwards, which is why they have to be
 * registered once per shortcode.
 */
class MpesaCallbackRegistrationService
{
    public function __construct(protected readonly MpesaClient $client) {}

    /**
     * Register the callback URLs for a shortcode.
     *
     * Everything defaults to configuration; a caller may only override the
     * shortcode, the two URLs and the response type. M-PESA's own body is
     * logged, not returned: the caller gets what was registered, with
     * Safaricom's description alongside it.
     *
     * @param  array{shortcode?: int|string|null, confirmation_url?: string|null, validation_url?: string|null, response_type?: string|null}  $overrides
     * @return array<string, string|null>
     *
     * @throws MpesaException
     */
    public function registerUrls(array $overrides = []): array
    {
        $shortcode = (string) ($overrides['shortcode'] ?? config('mpesa.c2b.shortcode'));
        $responseType = (string) ($overrides['response_type'] ?? config('mpesa.c2b.response_type'));
        $confirmationUrl = (string) ($overrides['confirmation_url'] ?? config('mpesa.callbacks.confirmation_url'));
        $validationUrl = (string) ($overrides['validation_url'] ?? config('mpesa.callbacks.validation_url'));

        $response = $this->client->postToMpesa('register_url', [
            'ShortCode' => $shortcode,
            'ResponseType' => $responseType,
            'ConfirmationURL' => $confirmationUrl,
            'ValidationURL' => $validationUrl,
        ]);

        return [
            'shortcode' => $shortcode,
            'response_type' => $responseType,
            'confirmation_url' => $confirmationUrl,
            'validation_url' => $validationUrl,
            'mpesa_description' => isset($response['ResponseDescription'])
                ? (string) $response['ResponseDescription']
                : null,
        ];
    }
}
