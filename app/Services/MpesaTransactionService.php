<?php

namespace App\Services;

use App\Exceptions\MpesaException;

/**
 * The Daraja APIs that ask M-PESA about, or undo, a transaction.
 *
 * All three are asynchronous: M-PESA acknowledges the request with a
 * conversation id and sends the real answer to the result URL later, which
 * arrives back as a queued result callback.
 */
class MpesaTransactionService
{
    public function __construct(protected readonly MpesaClient $client) {}

    /**
     * Ask M-PESA what became of a transaction.
     *
     * @param  string  $transactionId  The M-PESA receipt, for example SGR51YQ9AB.
     * @return array<string, string|null>
     *
     * @throws MpesaException
     */
    public function checkTransactionStatus(string $transactionId, ?string $partyA = null, string $remarks = 'Status query'): array
    {
        $response = $this->client->postToMpesa('transaction_status', [
            'Initiator' => (string) config('mpesa.initiator.name'),
            'SecurityCredential' => (string) config('mpesa.initiator.security_credential'),
            'CommandID' => 'TransactionStatusQuery',
            'TransactionID' => $transactionId,
            'PartyA' => (string) ($partyA ?? config('mpesa.c2b.shortcode')),
            'IdentifierType' => (string) config('mpesa.initiator.identifier_type'),
            'ResultURL' => (string) config('mpesa.callbacks.result_url'),
            'QueueTimeOutURL' => (string) config('mpesa.callbacks.timeout_url'),
            'Remarks' => $remarks,
            'Occasion' => $transactionId,
        ]);

        return $this->accepted($response, "Status query accepted for {$transactionId}");
    }

    /**
     * Ask M-PESA for the balance of one of our shortcodes.
     *
     * @return array<string, string|null>
     *
     * @throws MpesaException
     */
    public function checkAccountBalance(?string $shortcode = null, string $remarks = 'Balance query'): array
    {
        $response = $this->client->postToMpesa('account_balance', [
            'Initiator' => (string) config('mpesa.initiator.name'),
            'SecurityCredential' => (string) config('mpesa.initiator.security_credential'),
            'CommandID' => 'AccountBalance',
            'PartyA' => (string) ($shortcode ?? config('mpesa.c2b.shortcode')),
            'IdentifierType' => (string) config('mpesa.initiator.identifier_type'),
            'Remarks' => $remarks,
            'ResultURL' => (string) config('mpesa.callbacks.result_url'),
            'QueueTimeOutURL' => (string) config('mpesa.callbacks.timeout_url'),
        ]);

        return $this->accepted($response, 'Balance query accepted');
    }

    /**
     * Send a payment back to the customer who made it.
     *
     * @param  float|int  $amount  The amount to reverse, which may be less than the original.
     * @return array<string, string|null>
     *
     * @throws MpesaException
     */
    public function reverseTransaction(string $transactionId, float|int $amount, ?string $receiver = null, string $remarks = 'Reversal'): array
    {
        $response = $this->client->postToMpesa('reversal', [
            'Initiator' => (string) config('mpesa.initiator.name'),
            'SecurityCredential' => (string) config('mpesa.initiator.security_credential'),
            'CommandID' => 'TransactionReversal',
            'TransactionID' => $transactionId,
            'Amount' => (int) round((float) $amount),
            'ReceiverParty' => (string) ($receiver ?? config('mpesa.c2b.shortcode')),
            // Safaricom spells this field "Reciever".
            'RecieverIdentifierType' => '11',
            'ResultURL' => (string) config('mpesa.callbacks.result_url'),
            'QueueTimeOutURL' => (string) config('mpesa.callbacks.timeout_url'),
            'Remarks' => $remarks,
            'Occasion' => $transactionId,
        ]);

        return $this->accepted($response, "Reversal accepted for {$transactionId}");
    }

    /**
     * The shape every asynchronous request answers with.
     *
     * M-PESA's acknowledgement carries only the ids to follow the request
     * up with; the outcome arrives later on the result callback.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, string|null>
     */
    public function accepted(array $response, string $message): array
    {
        return [
            'conversation_id' => isset($response['ConversationID']) ? (string) $response['ConversationID'] : null,
            'originator_conversation_id' => isset($response['OriginatorConversationID'])
                ? (string) $response['OriginatorConversationID']
                : null,
            'mpesa_description' => isset($response['ResponseDescription'])
                ? (string) $response['ResponseDescription']
                : $message,
        ];
    }
}
