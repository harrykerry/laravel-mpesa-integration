<?php

namespace App\Services;

use App\Models\MpesaConfirmation;
use App\Models\MpesaStkPayments;
use App\Traits\MobileFormattingTrait;

class MpesaDataFetchService
{

    use MobileFormattingTrait;


    /**
     * Fetches C2B transactions for a specific Business Shortcode
     * 
     * @param int $shortcode 
     * @return array Response containing the fetch data or an error
     */


    public function fetchPayments(int $shortcode): array
    {

        $payments = MpesaConfirmation::where('business_shortcode', $shortcode)->get();

        if ($payments->isEmpty()) {

            return [
                'status' => 'error',
                'message' => "Payments for business shortcode {$shortcode} not found"
            ];
        }

        return [

            'status' => 'success',
            'message' => 'Fetch Successful',
            'data' => $payments
        ];
    }


    /**
     * Fetches successful STK transactions for a specific Business Shortcode
     * 
     * @param string $msisdn 
     * @return array Response containing the fetch data or an error
     */


    public function fetchStkPayments(string $msisdn): array
    {

        $formattedMsisdn = $this->sanitizeAndFormatMobile($msisdn);

        $payments = MpesaStkPayments::where('msisdn', $formattedMsisdn)->whereNotNull('transaction_id')->get();

        if ($payments->isEmpty()) {

            return [
                'status' => 'error',
                'message' => "Payments for business shortcode {$formattedMsisdn} not found"
            ];
        }

        return [

            'status' => 'success',
            'message' => 'Fetch Successful',
            'data' => $payments
        ];
    }
}
