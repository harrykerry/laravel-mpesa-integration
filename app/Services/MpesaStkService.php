<?php

namespace App\Services;

use App\Exceptions\MpesaException;
use App\Models\MpesaStkPayment;
use App\Traits\MobileFormattingTrait;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * Initiates STK (Lipa na M-PESA Online) pushes and records their outcome.
 *
 * Shortcode, passkey and callback URL always come from configuration. A
 * caller may choose the amount, the payer and the account reference, but
 * never the credentials or the URL M-PESA calls back.
 */
class MpesaStkService
{
    use MobileFormattingTrait;

    public function __construct(protected readonly MpesaClient $client) {}

    /**
     * Push a payment request to a customer's handset.
     *
     * @param  array{amount: int|float|string, msisdn: string, account_reference?: string|null, transaction_desc?: string|null, transaction_type?: string|null, shortcode?: int|string|null, party_b?: int|string|null}  $data
     * @return array<string, mixed>
     *
     * @throws MpesaException
     */
    public function push(array $data): array
    {
        $shortcode = $this->required($data['shortcode'] ?? config('mpesa.stk.shortcode'), 'mpesa.stk.shortcode');
        $passkey = $this->required(config('mpesa.stk.passkey'), 'mpesa.stk.passkey');
        $callbackUrl = $this->required(config('mpesa.callbacks.stk_url'), 'mpesa.callbacks.stk_url');

        $msisdn = $this->sanitizeAndFormatMobile((string) $data['msisdn']);
        $amount = (int) round((float) $data['amount']);
        $reference = (string) ($data['account_reference'] ?? config('mpesa.stk.account_reference'));

        ['password' => $password, 'timestamp' => $timestamp] = $this->password($shortcode, $passkey);

        $response = $this->client->post('stk_push', [
            'BusinessShortCode' => $shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => (string) ($data['transaction_type'] ?? config('mpesa.stk.transaction_type')),
            'Amount' => $amount,
            'PartyA' => $msisdn,
            'PartyB' => (string) ($data['party_b'] ?? config('mpesa.stk.party_b') ?? $shortcode),
            'PhoneNumber' => $msisdn,
            'CallBackURL' => $callbackUrl,
            'AccountReference' => $reference,
            'TransactionDesc' => (string) ($data['transaction_desc'] ?? "Payment of {$amount} to {$shortcode}"),
        ]);

        $this->recordPush($response, $shortcode, $msisdn, $amount);

        return $response;
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
            $this->log()->warning('STK_CALLBACK: push was not completed', [
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
            $this->log()->error('STK_CALLBACK: no push was recorded for this callback', [
                'merchant_request_id' => $merchantRequestId,
                'checkout_request_id' => $checkoutRequestId,
            ]);

            return ['status' => 'error', 'message' => "Record not found for {$merchantRequestId}"];
        }

        $metadata = $this->metadata($callbackData);

        $payment->fill([
            'transaction_id' => $metadata['MpesaReceiptNumber'] ?? null,
            'transaction_date' => $metadata['TransactionDate'] ?? null,
            'amount' => $metadata['Amount'] ?? $payment->amount,
            'msisdn' => isset($metadata['PhoneNumber'])
                ? $this->sanitizeAndFormatMobile((string) $metadata['PhoneNumber'])
                : $payment->msisdn,
        ])->save();

        $this->log()->info('STK_CALLBACK: payment confirmed', [
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
    protected function metadata(array $callbackData): array
    {
        return collect($callbackData['CallbackMetadata']['Item'] ?? [])
            ->filter(fn ($item) => is_array($item) && isset($item['Name']))
            ->mapWithKeys(fn (array $item) => [$item['Name'] => $item['Value'] ?? null])
            ->all();
    }

    /**
     * Save the push M-PESA accepted so its callback can be matched to it.
     *
     * @param  array<string, mixed>  $response
     */
    protected function recordPush(array $response, string $shortcode, string $msisdn, int $amount): void
    {
        $merchantRequestId = $response['MerchantRequestID'] ?? null;
        $checkoutRequestId = $response['CheckoutRequestID'] ?? null;

        // Without both ids the callback could never be matched to this push.
        if (blank($merchantRequestId) || blank($checkoutRequestId)) {
            $this->log()->critical('STK: push accepted without request ids, cannot be reconciled', [
                'msisdn' => $msisdn,
                'amount' => $amount,
                'response' => $response,
            ]);

            return;
        }

        MpesaStkPayment::query()->updateOrCreate(
            [
                'merchant_request_id' => $merchantRequestId,
                'checkout_request_id' => $checkoutRequestId,
            ],
            [
                'business_shortcode' => $shortcode,
                'msisdn' => $msisdn,
                'amount' => $amount,
            ],
        );

        $this->log()->info('STK: push accepted by M-PESA', [
            'checkout_request_id' => $checkoutRequestId,
            'msisdn' => $msisdn,
            'amount' => $amount,
        ]);
    }

    /**
     * Build the Lipa na M-PESA password and the timestamp it is bound to.
     *
     * @return array{password: string, timestamp: string}
     */
    protected function password(string $shortcode, string $passkey): array
    {
        $timestamp = CarbonImmutable::now()->format('YmdHis');

        return [
            'password' => base64_encode($shortcode.$passkey.$timestamp),
            'timestamp' => $timestamp,
        ];
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

    /**
     * The M-PESA log channel.
     */
    protected function log(): LoggerInterface
    {
        return Log::channel((string) config('mpesa.log_channel'));
    }
}
