<?php

namespace App\Enums;

/**
 * The kinds of traffic M-PESA sends to this application.
 *
 * All of it is inbound: a customer pays Safaricom directly, or answers an
 * STK prompt, and Safaricom tells us about it afterwards.
 */
enum MpesaCallbackType: string
{
    case C2bValidation = 'c2b_validation';
    case C2bConfirmation = 'c2b_confirmation';
    case Stk = 'stk';
    case Result = 'result';
    case Timeout = 'timeout';

    /**
     * The field in the payload that identifies the transaction, if any.
     */
    public function referenceKey(): ?string
    {
        return match ($this) {
            self::C2bValidation, self::C2bConfirmation => 'TransID',
            self::Stk => 'Body.stkCallback.CheckoutRequestID',
            self::Result, self::Timeout => 'Result.ConversationID',
        };
    }
}
