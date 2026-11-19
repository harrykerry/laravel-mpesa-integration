<?php

namespace App\Enums;

/**
 * The asynchronous Daraja requests this application makes.
 *
 * Each is acknowledged immediately and answered later on the result
 * callback, so each one is written down and matched up when that arrives.
 */
enum MpesaRequestType: string
{
    case TransactionStatus = 'transaction_status';
    case AccountBalance = 'account_balance';
    case Reversal = 'reversal';
    case B2c = 'b2c';
    case B2b = 'b2b';
    case TaxRemittance = 'tax_remittance';

    /**
     * A human label for logs and listings.
     */
    public function label(): string
    {
        return match ($this) {
            self::TransactionStatus => 'Transaction status query',
            self::AccountBalance => 'Account balance query',
            self::Reversal => 'Reversal',
            self::B2c => 'Payment to a customer',
            self::B2b => 'Payment to a business',
            self::TaxRemittance => 'Tax remittance',
        };
    }
}
