<?php


namespace App\Services;

use App\Models\MpesaConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class MpesaCallBackService
{

    /**
     * Handles the M-PESA callback data and saves it to the database.

     * @param array $data The callback data from M-PESA.
     * 
     * @return array An associative array indicating the result of the operation.
     */

    public function handleCallBackData(array $data): array
    {

        // Extract data from the callback
        $transactionType = $data['TransactionType'];
        $transactionId = $data['TransID'];
        $transTime = $data['TransTime'];
        $transactionAmount = $data['TransAmount'];
        $businessShortcode = $data['BusinessShortCode'];
        $billRefNumber = $data['BillRefNumber'] ?? 'NA';
        $invoiceNumber = $data['InvoiceNumber'] ?? 'NA';
        $organizationBalance = $data['OrgAccountBalance'] ?? 'NA';
        $thirdPartyTransId = $data['ThirdPartyTransID'] ?? 'NA';
        $mobileNumber = $data['MSISDN'];
        $firstName = $data['FirstName'];
        $middleName = $data['MiddleName'] ?? 'NA';
        $lastName = $data['LastName'] ?? 'NA';


        try {

            $mpesaConfirmation = new MpesaConfirmation();
            $mpesaConfirmation->transaction_type = $transactionType;
            $mpesaConfirmation->transaction_id = $transactionId;
            $mpesaConfirmation->transaction_time = $transTime;
            $mpesaConfirmation->transaction_amount = $transactionAmount;
            $mpesaConfirmation->business_shortcode = $businessShortcode;
            $mpesaConfirmation->billref_no = $billRefNumber;
            $mpesaConfirmation->invoice_no = $invoiceNumber;
            $mpesaConfirmation->org_balance = $organizationBalance;
            $mpesaConfirmation->thirdparty_transid = $thirdPartyTransId;
            $mpesaConfirmation->mobile_number = $mobileNumber;
            $mpesaConfirmation->first_name = $firstName;
            $mpesaConfirmation->middle_name = $middleName;
            $mpesaConfirmation->last_name = $lastName;
            $mpesaConfirmation->save();

            Log::channel('mpesa')->info("CALLBACK: Callback data received and saved for {$transactionId}");

            return [
                'status' => 'success',
                'message' => 'Entry for ' . $transactionId . ' saved'
            ];
        } catch (\Exception $e) {

            $errorMessage = $e->getMessage();

            Log::channel('mpesa')->error("CALLBACK_ERROR: Error saving data for {$transactionId} : " . $errorMessage);
            return [
                'status' => 'error',
                'message' => $errorMessage
            ];
        }
    }
}
