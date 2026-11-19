<?php

namespace App\Services;

use App\Exceptions\MpesaException;
use App\Jobs\ForwardConfirmedPayment;
use App\Models\MpesaCallback;
use App\Models\MpesaConfirmation;
use App\Support\Msisdn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Throwable;

/**
 * Stores the C2B payments M-PESA confirms.
 *
 * Nothing here initiates a payment. A customer pays the shortcode directly
 * through M-PESA; Safaricom then calls our confirmation URL, the payload is
 * recorded by the inbox, and a queued job brings it here.
 */
class MpesaC2bService
{
    public function __construct(protected readonly MpesaClient $client) {}

    /**
     * Pretend a customer paid the shortcode, in the sandbox only.
     *
     * Safaricom's simulate endpoint exists so the confirmation flow can be
     * exercised without a real handset. It does not exist in production,
     * where a payment can only start with the customer.
     *
     * @return array<string, string|null>
     *
     * @throws MpesaException
     */
    public function simulateCustomerPayment(float|int $amount, string $msisdn, string $billReference = 'TEST'): array
    {
        if (config('mpesa.environment') !== 'sandbox') {
            throw new MpesaException(
                message: 'Payments can only be simulated in the sandbox.',
                errorCode: 'simulation.not_available',
                status: HttpStatus::HTTP_CONFLICT,
            );
        }

        $response = $this->client->postToMpesa('c2b_simulate', [
            'ShortCode' => (string) config('mpesa.c2b.shortcode'),
            'CommandID' => (string) config('mpesa.stk.transaction_type') === 'CustomerBuyGoodsOnline'
                ? 'CustomerBuyGoodsOnline'
                : 'CustomerPayBillOnline',
            'Amount' => (int) round((float) $amount),
            'Msisdn' => Msisdn::normalize($msisdn),
            'BillRefNumber' => $billReference,
        ]);

        return [
            'conversation_id' => isset($response['ConversationID']) ? (string) $response['ConversationID'] : null,
            'originator_conversation_id' => isset($response['OriginatorCoversationID'])
                ? (string) $response['OriginatorCoversationID']
                : null,
            'mpesa_description' => isset($response['ResponseDescription'])
                ? (string) $response['ResponseDescription']
                : 'Payment simulated',
        ];
    }

    /**
     * Store the payment a recorded confirmation callback describes.
     *
     * The receipt number is unique, so a payment is stored once however
     * often M-PESA resends it. The callback row is marked in the same
     * transaction as the payment, so the two can never disagree.
     */
    public function storeConfirmedPayment(MpesaCallback $callback): ?MpesaConfirmation
    {
        $payload = $callback->payload;
        $receipt = (string) ($payload['TransID'] ?? '');

        if ($receipt === '') {
            Log::channel('mpesa')->error('C2B_CALLBACK: payload has no TransID, nothing to store', [
                'callback_id' => $callback->id,
            ]);

            $callback->markIgnored('Payload has no TransID');

            return null;
        }

        DB::beginTransaction();

        try {
            $payment = MpesaConfirmation::query()->firstOrCreate(
                ['transaction_id' => $receipt],
                [
                    'transaction_type' => $payload['TransactionType'] ?? null,
                    'transaction_time' => $payload['TransTime'] ?? null,
                    'transaction_amount' => $payload['TransAmount'] ?? null,
                    'business_shortcode' => $payload['BusinessShortCode'] ?? null,
                    'billref_no' => $payload['BillRefNumber'] ?? null,
                    'invoice_no' => $payload['InvoiceNumber'] ?? null,
                    'org_balance' => $payload['OrgAccountBalance'] ?? null,
                    'thirdparty_transid' => $payload['ThirdPartyTransID'] ?? null,
                    'mobile_number' => isset($payload['MSISDN']) ? Msisdn::normalize((string) $payload['MSISDN']) : null,
                    'first_name' => $payload['FirstName'] ?? null,
                    'middle_name' => $payload['MiddleName'] ?? null,
                    'last_name' => $payload['LastName'] ?? null,
                ],
            );

            $callback->markProcessed();

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if (!$payment->wasRecentlyCreated) {
            Log::channel('mpesa')->warning('C2B_CALLBACK: payment already stored, left untouched', [
                'callback_id' => $callback->id,
                'transaction_id' => $receipt,
            ]);

            return $payment;
        }

        Log::channel('mpesa')->info('C2B_CALLBACK: payment stored', [
            'callback_id' => $callback->id,
            'transaction_id' => $receipt,
            'amount' => $payment->transaction_amount,
            'msisdn' => $payment->mobile_number,
        ]);

        ForwardConfirmedPayment::dispatch('c2b', [
            'transaction_id' => $payment->transaction_id,
            'amount' => $payment->transaction_amount,
            'msisdn' => $payment->mobile_number,
            'business_shortcode' => $payment->business_shortcode,
            'bill_reference' => $payment->billref_no,
            'transaction_time' => $payment->transaction_time?->toIso8601String(),
            'payer_name' => $payment->customer_name,
        ]);

        return $payment;
    }
}
