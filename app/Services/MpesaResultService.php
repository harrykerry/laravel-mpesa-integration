<?php

namespace App\Services;

use App\Models\MpesaCallback;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Takes in the outcome of an asynchronous Daraja request.
 *
 * Transaction status, account balance, reversal, B2C, B2B and tax
 * remittance all answer twice: an immediate acknowledgement with a
 * conversation id, then the real outcome, posted to the result URL. The
 * timeout URL is called instead when M-PESA's queue gives up.
 *
 * To act on an outcome, add to handle() below: the conversation id ties it
 * back to the request that started it.
 */
class MpesaResultService
{
    /**
     * Record a result or timeout callback that has been queued.
     */
    public function handle(MpesaCallback $callback): void
    {
        $result = Arr::get($callback->payload, 'Result', []);

        $resultCode = Arr::get($result, 'ResultCode');
        $context = [
            'callback_id' => $callback->id,
            'type' => $callback->type->value,
            'conversation_id' => Arr::get($result, 'ConversationID'),
            'originator_conversation_id' => Arr::get($result, 'OriginatorConversationID'),
            'transaction_id' => Arr::get($result, 'TransactionID'),
            'result_code' => $resultCode,
            'result_desc' => Arr::get($result, 'ResultDesc'),
            'parameters' => $this->parameters($result),
        ];

        if ($resultCode !== null && (int) $resultCode !== 0) {
            Log::channel('mpesa')->warning('RESULT: request did not succeed', $context);
        } else {
            Log::channel('mpesa')->info('RESULT: request succeeded', $context);
        }

        // Act on the outcome here. The conversation id is what the request
        // that started this was given, so it is what to look it up by.

        $callback->markProcessed();
    }

    /**
     * Flatten M-PESA's ResultParameters list into a keyed array.
     *
     * Balances, receipts and amounts all arrive in this shape.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function parameters(array $result): array
    {
        $parameters = Arr::get($result, 'ResultParameters.ResultParameter', []);

        // A single parameter arrives unwrapped.
        if (isset($parameters['Key'])) {
            $parameters = [$parameters];
        }

        return collect($parameters)
            ->filter(fn ($parameter) => is_array($parameter) && isset($parameter['Key']))
            ->mapWithKeys(fn (array $parameter) => [$parameter['Key'] => $parameter['Value'] ?? null])
            ->all();
    }
}
