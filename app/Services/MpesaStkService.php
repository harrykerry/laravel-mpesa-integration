<?php

namespace App\Services;

use App\Exceptions\MpesaException;
use App\Models\MpesaStkPayment;
use App\Support\Msisdn;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Initiates STK (Lipa na M-PESA Online) pushes and records their outcome.
 *
 * Shortcode, passkey and callback URL always come from configuration. A
 * caller may choose the amount, the payer and the account reference, but
 * never the credentials or the URL M-PESA calls back.
 */
class MpesaStkService
{
    public function __construct(protected readonly MpesaClient $client) {}

    /**
     * Ask M-PESA to prompt a customer to pay.
     *
     * The caller supplies only the payer and the amount. The shortcode,
     * passkey, party B, transaction type, account reference and callback URL
     * are read from configuration. M-PESA's own body is logged, not
     * returned: the caller gets the two ids needed to follow the payment up,
     * plus the message Safaricom wants shown to the customer.
     *
     * @return array<string, string|null>
     *
     * @throws MpesaException
     */
    public function initiatePush(float|int|string $amount, string $msisdn): array
    {
        $shortcode = (string) config('mpesa.stk.shortcode');
        $passkey = (string) config('mpesa.stk.passkey');

        $msisdn = Msisdn::normalize($msisdn);
        $amount = (int) round((float) $amount);

        ['password' => $password, 'timestamp' => $timestamp] = $this->buildPassword($shortcode, $passkey);

        $response = $this->client->postToMpesa('stk_push', [
            'BusinessShortCode' => $shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => (string) config('mpesa.stk.transaction_type'),
            'Amount' => $amount,
            'PartyA' => $msisdn,
            'PartyB' => (string) (config('mpesa.stk.party_b') ?: $shortcode),
            'PhoneNumber' => $msisdn,
            'CallBackURL' => (string) config('mpesa.callbacks.stk_url'),
            'AccountReference' => (string) config('mpesa.stk.account_reference'),
            'TransactionDesc' => (string) config('mpesa.stk.account_reference'),
        ]);

        $this->recordInitiatedPush($response, $shortcode, $msisdn, $amount);

        return [
            'merchant_request_id' => isset($response['MerchantRequestID']) ? (string) $response['MerchantRequestID'] : null,
            'checkout_request_id' => isset($response['CheckoutRequestID']) ? (string) $response['CheckoutRequestID'] : null,
            'customer_message' => isset($response['CustomerMessage'])
                ? (string) $response['CustomerMessage']
                : 'A payment request has been sent to the customer.',
        ];
    }

    /**
     * Record the outcome M-PESA reports for a push we initiated.
     *
     * @param  array<string, mixed>  $callbackData  The Body.stkCallback payload.
     * @return array{status: string, message: string}
     */
    public function handleStkCallbackData(array $callbackData): array
    {
        $merchantRequestId = (string) ($callbackData['MerchantRequestID'] ?? '');
        $checkoutRequestId = (string) ($callbackData['CheckoutRequestID'] ?? '');
        $resultCode = (int) ($callbackData['ResultCode'] ?? -1);
        $resultDesc = (string) ($callbackData['ResultDesc'] ?? 'No result description');

        if ($resultCode !== 0) {
            Log::channel((string) config('mpesa.log_channel'))->warning('STK_CALLBACK: push was not completed', [
                'merchant_request_id' => $merchantRequestId,
                'checkout_request_id' => $checkoutRequestId,
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
            ]);

            return ['status' => 'error', 'message' => $resultDesc];
        }

        $payment = MpesaStkPayment::query()
            ->forRequest($merchantRequestId, $checkoutRequestId)
            ->first();

        if (! $payment) {
            Log::channel((string) config('mpesa.log_channel'))->error('STK_CALLBACK: no push was recorded for this callback', [
                'merchant_request_id' => $merchantRequestId,
                'checkout_request_id' => $checkoutRequestId,
            ]);

            return ['status' => 'error', 'message' => "Record not found for {$merchantRequestId}"];
        }

        $metadata = $this->extractCallbackMetadata($callbackData);

        $payment->fill([
            'transaction_id' => $metadata['MpesaReceiptNumber'] ?? null,
            'transaction_date' => $metadata['TransactionDate'] ?? null,
            'amount' => $metadata['Amount'] ?? $payment->amount,
            'msisdn' => isset($metadata['PhoneNumber'])
                ? Msisdn::normalize((string) $metadata['PhoneNumber'])
                : $payment->msisdn,
        ])->save();

        Log::channel((string) config('mpesa.log_channel'))->info('STK_CALLBACK: payment confirmed', [
            'transaction_id' => $payment->transaction_id,
            'checkout_request_id' => $checkoutRequestId,
        ]);

        return ['status' => 'success', 'message' => "Entry for {$payment->transaction_id} updated successfully"];
    }

    /**
     * Flatten M-PESA's CallbackMetadata list into a keyed array.
     *
     * @param  array<string, mixed>  $callbackData
     * @return array<string, mixed>
     */
    public function extractCallbackMetadata(array $callbackData): array
    {
        return collect($callbackData['CallbackMetadata']['Item'] ?? [])
            ->filter(fn ($item) => is_array($item) && isset($item['Name']))
            ->mapWithKeys(fn (array $item) => [$item['Name'] => $item['Value'] ?? null])
            ->all();
    }

    /**
     * Save the push M-PESA accepted so its callback can be matched to it.
     *
     * M-PESA has already prompted the customer by the time this runs, so a
     * database failure here must not turn into a failed response: the push
     * is real either way. It is logged as critical instead, and the raw
     * callback is still kept when it arrives.
     *
     * @param  array<string, mixed>  $response
     */
    public function recordInitiatedPush(array $response, string $shortcode, string $msisdn, int $amount): ?MpesaStkPayment
    {
        $merchantRequestId = $response['MerchantRequestID'] ?? null;
        $checkoutRequestId = $response['CheckoutRequestID'] ?? null;

        // Without both ids the callback could never be matched to this push.
        if (blank($merchantRequestId) || blank($checkoutRequestId)) {
            Log::channel((string) config('mpesa.log_channel'))->critical('STK: push accepted without request ids, cannot be reconciled', [
                'msisdn' => $msisdn,
                'amount' => $amount,
                'response' => $response,
            ]);

            return null;
        }

        try {
            $payment = DB::transaction(fn (): MpesaStkPayment => MpesaStkPayment::query()->updateOrCreate(
                [
                    'merchant_request_id' => $merchantRequestId,
                    'checkout_request_id' => $checkoutRequestId,
                ],
                [
                    'business_shortcode' => $shortcode,
                    'msisdn' => $msisdn,
                    'amount' => $amount,
                ],
            ));
        } catch (Throwable $e) {
            Log::channel((string) config('mpesa.log_channel'))->critical('STK: push accepted but could not be stored', [
                'checkout_request_id' => $checkoutRequestId,
                'merchant_request_id' => $merchantRequestId,
                'msisdn' => $msisdn,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        Log::channel((string) config('mpesa.log_channel'))->info('STK: push accepted by M-PESA', [
            'checkout_request_id' => $checkoutRequestId,
            'msisdn' => $msisdn,
            'amount' => $amount,
        ]);

        return $payment;
    }

    /**
     * Build the Lipa na M-PESA password and the timestamp it is bound to.
     *
     * @return array{password: string, timestamp: string}
     */
    public function buildPassword(string $shortcode, string $passkey): array
    {
        $timestamp = CarbonImmutable::now()->format('YmdHis');

        return [
            'password' => base64_encode($shortcode.$passkey.$timestamp),
            'timestamp' => $timestamp,
        ];
    }
}
