<?php

namespace App\Services;

use App\Enums\C2bRejection;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether M-PESA should complete a C2B payment.
 *
 * This is the only callback M-PESA waits on. It is answered inline, never
 * queued, and must stay fast: Safaricom times out in seconds and will then
 * fall back to the response type configured for the shortcode.
 *
 * Accepting means the customer is deducted and a confirmation follows.
 * Rejecting means the customer is not deducted and no confirmation is sent.
 *
 * To add a business rule, put it in checks() below. Return null to accept,
 * or a C2bRejection to stop the payment. The codes come from Safaricom, so
 * use the one that best describes the reason: the customer sees it.
 */
class MpesaC2bValidationService
{
    /**
     * Decide on a payment M-PESA is holding.
     *
     * @param  array<string, mixed>  $payload  The validation callback payload.
     */
    public function decide(array $payload): ?C2bRejection
    {
        if (! config('mpesa.validation.enabled')) {
            return null;
        }

        $rejection = $this->checks($payload);

        Log::channel('mpesa')->info(
            $rejection === null
                ? 'C2B_VALIDATION: payment accepted'
                : 'C2B_VALIDATION: payment rejected',
            [
                'transaction_id' => $payload['TransID'] ?? null,
                'amount' => $payload['TransAmount'] ?? null,
                'msisdn' => $payload['MSISDN'] ?? null,
                'bill_reference' => $payload['BillRefNumber'] ?? null,
                'rejection_code' => $rejection?->value,
                'rejection_reason' => $rejection?->description(),
            ],
        );

        return $rejection;
    }

    /**
     * The business rules. Add yours here.
     *
     * The rules below are driven by config('mpesa.validation'): the payment
     * must be for one of our shortcodes, within the amount bounds, and its
     * bill reference must match the expected pattern. Each is skipped when
     * its setting is left unset.
     *
     * @param  array<string, mixed>  $payload
     */
    public function checks(array $payload): ?C2bRejection
    {
        $shortcode = (string) ($payload['BusinessShortCode'] ?? '');
        $amount = (float) ($payload['TransAmount'] ?? 0);
        $billReference = trim((string) ($payload['BillRefNumber'] ?? ''));
        $msisdn = (string) ($payload['MSISDN'] ?? '');

        $expectedShortcode = (string) config('mpesa.c2b.shortcode');
        $minAmount = config('mpesa.validation.min_amount');
        $maxAmount = config('mpesa.validation.max_amount');
        $pattern = config('mpesa.validation.bill_reference_pattern');

        if ($msisdn === '') {
            return C2bRejection::InvalidMsisdn;
        }

        if (config('mpesa.validation.require_known_shortcode') && filled($expectedShortcode) && $shortcode !== $expectedShortcode) {
            return C2bRejection::InvalidShortcode;
        }

        // An unset bound arrives as null or as an empty string from the
        // environment file, so only a numeric bound is applied.
        if (is_numeric($minAmount) && $amount < (float) $minAmount) {
            return C2bRejection::InvalidAmount;
        }

        if (is_numeric($maxAmount) && $amount > (float) $maxAmount) {
            return C2bRejection::InvalidAmount;
        }

        if (config('mpesa.validation.require_bill_reference') && $billReference === '') {
            return C2bRejection::InvalidAccountNumber;
        }

        if (filled($pattern) && $billReference !== '' && preg_match((string) $pattern, $billReference) !== 1) {
            return C2bRejection::InvalidAccountNumber;
        }

        // Add further rules here, for example: look the bill reference up as
        // an invoice and reject C2bRejection::InvalidAccountNumber when it
        // is unknown, already paid, or the amount does not match.

        return null;
    }
}
