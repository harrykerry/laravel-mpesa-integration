<?php

namespace App\Enums;

/**
 * The reasons M-PESA accepts for refusing a C2B payment.
 *
 * Returning one of these from the validation callback stops the payment:
 * the customer is not deducted. Any other answer means "accepted", and
 * M-PESA completes the payment and sends a confirmation.
 *
 * These codes are Safaricom's; they cannot be invented.
 */
enum C2bRejection: string
{
    case InvalidMsisdn = 'C2B00011';
    case InvalidAccountNumber = 'C2B00012';
    case InvalidAmount = 'C2B00013';
    case InvalidKyc = 'C2B00014';
    case InvalidShortcode = 'C2B00015';
    case OtherError = 'C2B00016';

    /**
     * The description M-PESA expects beside the code.
     */
    public function description(): string
    {
        return match ($this) {
            self::InvalidMsisdn => 'Invalid MSISDN',
            self::InvalidAccountNumber => 'Invalid Account Number',
            self::InvalidAmount => 'Invalid Amount',
            self::InvalidKyc => 'Invalid KYC Details',
            self::InvalidShortcode => 'Invalid Shortcode',
            self::OtherError => 'Other Error',
        };
    }
}
