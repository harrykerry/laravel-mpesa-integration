<?php


namespace App\Services;

use App\Models\MpesaConfirmation;
use Illuminate\Support\Facades\Log;

class MpesaCallBackService
{

/**
 * Handles the M-PESA callback data and saves it to the database.

 * @param array $data The callback data from M-PESA.
 * 
 * @return \Illuminate\Http\JsonResponse A JSON response indicating the success or failure of the operation.
 * 
 * @throws \Exception If an error occurs while saving the data to the database.
 */

    public function handleCallBackData($data)
    {

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

            Log::channel('mpesa')->info('Mpesa Data Saved. TransId - ' . $transactionId);

            return response()->json(['message'=> 'Confirmation Data Saved SUccesfully'],200);
        } catch (\Exception $e) {

            $errorMessage = $e->getMessage();

            Log::channel('mpesa')->error('Error saving data for' . $transactionId . $errorMessage);
            return response()->json(['error'=> 'Something Went Wrong'],500);
        }
    }
}
