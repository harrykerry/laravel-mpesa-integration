<?php

namespace App\Services;

use App\Models\MpesaConfirmation;
use App\Models\MpesaStkPayment;
use App\Traits\MobileFormattingTrait;

class MpesaDataFetchService
{
    use MobileFormattingTrait;

    /**
     * Fetches C2B transactions for a specific Business Shortcode
     *
     * @return array Response containing the fetch data or an error
     */
    public function fetchPayments(int $shortcode): array
    {

        $payments = MpesaConfirmation::query()->forShortcode($shortcode)->latestFirst()->get();

        if ($payments->isEmpty()) {

            return [
                'status' => 'error',
                'message' => "Payments for business shortcode {$shortcode} not found",
            ];
        }

        return [

            'status' => 'success',
            'message' => 'Fetch Successful',
            'data' => $payments,
        ];
    }

    /**
     * Fetches successful STK transactions for a specific Business Shortcode
     *
     * @return array Response containing the fetch data or an error
     */
    public function fetchStkPayments(string $msisdn): array
    {

        $formattedMsisdn = $this->sanitizeAndFormatMobile($msisdn);

        $payments = MpesaStkPayment::query()->forMsisdn($formattedMsisdn)->paid()->latestFirst()->get();

        if ($payments->isEmpty()) {

            return [
                'status' => 'error',
                'message' => "Payments for business shortcode {$formattedMsisdn} not found",
            ];
        }

        return [

            'status' => 'success',
            'message' => 'Fetch Successful',
            'data' => $payments,
        ];
    }
}
