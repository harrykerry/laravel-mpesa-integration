<?php

namespace App\Services;

use App\Exceptions\MpesaException;
use App\Jobs\ForwardConfirmedPayment;
use App\Models\MpesaCallback;
use App\Models\MpesaStkPayment;
use App\Support\Msisdn;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
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
     * Ask M-PESA what became of a push, instead of waiting for its callback.
     *
     * Useful when a callback never arrived, or when a frontend polls while
     * the customer is still looking at the prompt. A push M-PESA is still
     * processing is reported as pending rather than as a failure, and a
     * final outcome is written to the push row if the callback has not
     * already done it.
     *
     * @return array<string, string|int|null>
     *
     * @throws MpesaException
     */
    public function checkPushStatus(string $checkoutRequestId): array
    {
        $shortcode = (string) config('mpesa.stk.shortcode');
        ['password' => $password, 'timestamp' => $timestamp] = $this->buildPassword(
            $shortcode,
            (string) config('mpesa.stk.passkey'),
        );

        try {
            $response = $this->client->postToMpesa('stk_query', [
                'BusinessShortCode' => $shortcode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'CheckoutRequestID' => $checkoutRequestId,
            ], failOnNonZeroCode: false);
        } catch (MpesaException $e) {
            // M-PESA reports a push it has not finished with as an error.
            if ($e->errorCode === '500.001.1001') {
                return [
                    'checkout_request_id' => $checkoutRequestId,
                    'status' => 'pending',
                    'result_code' => null,
                    'result_desc' => null,
                    'mpesa_description' => 'The transaction is still being processed.',
                ];
            }

            throw $e;
        }

        $resultCode = isset($response['ResultCode']) ? (int) $response['ResultCode'] : null;
        $resultDesc = isset($response['ResultDesc']) ? (string) $response['ResultDesc'] : null;

        $payment = MpesaStkPayment::query()
            ->where('checkout_request_id', $checkoutRequestId)
            ->first();

        if ($payment && $resultCode !== null && $payment->result_code === null) {
            $payment->fill([
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
                'completed_at' => now(),
            ])->save();

            Log::channel('mpesa')->info('STK_QUERY: outcome written from a status query', [
                'checkout_request_id' => $checkoutRequestId,
                'result_code' => $resultCode,
            ]);
        }

        return [
            'checkout_request_id' => $checkoutRequestId,
            'status' => $payment?->status ?? match ($resultCode) {
                0 => 'paid',
                null => 'pending',
                default => 'failed',
            },
            'result_code' => $resultCode,
            'result_desc' => $resultDesc,
            'transaction_id' => $payment?->transaction_id,
            'mpesa_description' => $resultDesc ?? 'Status retrieved',
        ];
    }

    /**
     * Record the outcome M-PESA reports for a push we initiated.
     *
     * Runs on the queue: the endpoint has already acknowledged M-PESA. The
     * push row is locked for the update so two deliveries of the same
     * callback cannot both write it, and the callback row is marked in the
     * same transaction as the payment.
     *
     * @throws RuntimeException When the push has not been recorded yet, so the job retries.
     */
    public function recordPushOutcome(MpesaCallback $callback): ?MpesaStkPayment
    {
        $data = Arr::get($callback->payload, 'Body.stkCallback', []);
        $merchantRequestId = (string) ($data['MerchantRequestID'] ?? '');
        $checkoutRequestId = (string) ($data['CheckoutRequestID'] ?? '');
        $resultCode = (int) ($data['ResultCode'] ?? -1);
        $resultDesc = (string) ($data['ResultDesc'] ?? 'No result description');

        if ($merchantRequestId === '' || $checkoutRequestId === '') {
            Log::channel('mpesa')->error('STK_CALLBACK: payload has no request ids, cannot be matched', [
                'callback_id' => $callback->id,
            ]);

            $callback->markIgnored('Payload has no MerchantRequestID or CheckoutRequestID');

            return null;
        }

        $metadata = $this->extractCallbackMetadata($data);

        ['payment' => $payment, 'already_paid' => $alreadyPaid] = DB::transaction(function () use ($callback, $merchantRequestId, $checkoutRequestId, $resultCode, $resultDesc, $metadata): array {
            $payment = MpesaStkPayment::query()
                ->forRequest($merchantRequestId, $checkoutRequestId)
                ->lockForUpdate()
                ->first();

            if (!$payment) {
                // The push may still be mid-write, so let the job retry.
                throw new RuntimeException("No STK push recorded for {$checkoutRequestId}");
            }

            if ($payment->is_paid) {
                $callback->markIgnored('Push already has a receipt');

                return ['payment' => $payment, 'already_paid' => true];
            }

            $payment->fill(array_filter([
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
                'completed_at' => now(),
                'transaction_id' => $metadata['MpesaReceiptNumber'] ?? null,
                'transaction_date' => $metadata['TransactionDate'] ?? null,
                'amount' => $metadata['Amount'] ?? null,
                'msisdn' => isset($metadata['PhoneNumber'])
                    ? Msisdn::normalize((string) $metadata['PhoneNumber'])
                    : null,
            ], fn ($value) => $value !== null))->save();

            $callback->markProcessed();

            return ['payment' => $payment, 'already_paid' => false];
        });

        if ($alreadyPaid) {
            Log::channel('mpesa')->warning('STK_CALLBACK: push already had a receipt, left untouched', [
                'callback_id' => $callback->id,
                'checkout_request_id' => $checkoutRequestId,
                'transaction_id' => $payment->transaction_id,
            ]);

            return $payment;
        }

        if ($resultCode !== 0) {
            Log::channel('mpesa')->warning('STK_CALLBACK: push was not completed', [
                'callback_id' => $callback->id,
                'checkout_request_id' => $checkoutRequestId,
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
            ]);

            return $payment;
        }

        Log::channel('mpesa')->info('STK_CALLBACK: payment confirmed', [
            'callback_id' => $callback->id,
            'checkout_request_id' => $checkoutRequestId,
            'transaction_id' => $payment->transaction_id,
            'amount' => $payment->amount,
        ]);

        ForwardConfirmedPayment::dispatch('stk', [
            'transaction_id' => $payment->transaction_id,
            'amount' => $payment->amount,
            'msisdn' => $payment->msisdn,
            'business_shortcode' => $payment->business_shortcode,
            'checkout_request_id' => $payment->checkout_request_id,
            'merchant_request_id' => $payment->merchant_request_id,
            'transaction_date' => $payment->transaction_date?->toIso8601String(),
        ]);

        return $payment;
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
            Log::channel('mpesa')->critical('STK: push accepted without request ids, cannot be reconciled', [
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
            Log::channel('mpesa')->critical('STK: push accepted but could not be stored', [
                'checkout_request_id' => $checkoutRequestId,
                'merchant_request_id' => $merchantRequestId,
                'msisdn' => $msisdn,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        Log::channel('mpesa')->info('STK: push accepted by M-PESA', [
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
