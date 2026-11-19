<?php

namespace App\Services;

use App\Exceptions\MpesaException;
use App\Support\Msisdn;
use Illuminate\Support\Str;

/**
 * The Daraja APIs that move money out of our shortcode.
 *
 * B2C pays a customer, B2B pays another business, and tax remittance pays
 * the KRA. All three are asynchronous: M-PESA acknowledges with a
 * conversation id and sends the outcome to the result URL, which arrives
 * back as a queued result callback.
 *
 * The initiator, its security credential and the paying shortcode are
 * configuration. A caller only chooses who is paid and how much.
 */
class MpesaPayoutService
{
    public function __construct(
        protected readonly MpesaClient $client,
        protected readonly MpesaTransactionService $transactions,
    ) {}

    /**
     * Pay a customer from our shortcode (B2C).
     *
     * @return array<string, string|null>
     *
     * @throws MpesaException
     */
    public function sendToCustomer(float|int $amount, string $msisdn, string $remarks = 'Payment', ?string $commandId = null): array
    {
        $msisdn = Msisdn::normalize($msisdn);

        $response = $this->client->postToMpesa('b2c', [
            'OriginatorConversationID' => (string) Str::uuid(),
            'InitiatorName' => (string) config('mpesa.initiator.name'),
            'SecurityCredential' => (string) config('mpesa.initiator.security_credential'),
            'CommandID' => (string) ($commandId ?? config('mpesa.payouts.b2c.command_id')),
            'Amount' => (int) round((float) $amount),
            'PartyA' => (string) config('mpesa.payouts.shortcode'),
            'PartyB' => $msisdn,
            'Remarks' => $remarks,
            'QueueTimeOutURL' => (string) config('mpesa.callbacks.timeout_url'),
            'ResultURL' => (string) config('mpesa.callbacks.result_url'),
            'Occassion' => $remarks,
        ]);

        return $this->transactions->accepted($response, "Payment to {$msisdn} accepted");
    }

    /**
     * Pay another business from our shortcode (B2B).
     *
     * @param  string  $accountReference  The account to credit at the receiving paybill.
     * @return array<string, string|null>
     *
     * @throws MpesaException
     */
    public function payBusiness(
        float|int $amount,
        string $receiverShortcode,
        string $accountReference,
        string $remarks = 'Business payment',
        ?string $commandId = null,
    ): array {
        $response = $this->client->postToMpesa('b2b', [
            'Initiator' => (string) config('mpesa.initiator.name'),
            'SecurityCredential' => (string) config('mpesa.initiator.security_credential'),
            'CommandID' => (string) ($commandId ?? config('mpesa.payouts.b2b.command_id')),
            'SenderIdentifierType' => (string) config('mpesa.payouts.b2b.sender_identifier_type'),
            'RecieverIdentifierType' => (string) config('mpesa.payouts.b2b.receiver_identifier_type'),
            'Amount' => (int) round((float) $amount),
            'PartyA' => (string) config('mpesa.payouts.shortcode'),
            'PartyB' => $receiverShortcode,
            'AccountReference' => $accountReference,
            'Remarks' => $remarks,
            'QueueTimeOutURL' => (string) config('mpesa.callbacks.timeout_url'),
            'ResultURL' => (string) config('mpesa.callbacks.result_url'),
        ]);

        return $this->transactions->accepted($response, "Payment to {$receiverShortcode} accepted");
    }

    /**
     * Remit tax to the KRA.
     *
     * @param  string  $paymentRegistrationNumber  The PRN from iTax.
     * @return array<string, string|null>
     *
     * @throws MpesaException
     */
    public function remitTax(float|int $amount, string $paymentRegistrationNumber, string $remarks = 'Tax remittance'): array
    {
        $response = $this->client->postToMpesa('tax_remittance', [
            'Initiator' => (string) config('mpesa.initiator.name'),
            'SecurityCredential' => (string) config('mpesa.initiator.security_credential'),
            'CommandID' => (string) config('mpesa.payouts.tax.command_id'),
            'SenderIdentifierType' => (string) config('mpesa.payouts.b2b.sender_identifier_type'),
            'RecieverIdentifierType' => (string) config('mpesa.payouts.b2b.receiver_identifier_type'),
            'Amount' => (int) round((float) $amount),
            'PartyA' => (string) config('mpesa.payouts.shortcode'),
            'PartyB' => (string) config('mpesa.payouts.tax.receiver_shortcode'),
            'AccountReference' => $paymentRegistrationNumber,
            'Remarks' => $remarks,
            'QueueTimeOutURL' => (string) config('mpesa.callbacks.timeout_url'),
            'ResultURL' => (string) config('mpesa.callbacks.result_url'),
        ]);

        return $this->transactions->accepted($response, 'Tax remittance accepted');
    }
}
