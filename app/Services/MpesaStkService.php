<?php

namespace App\Services;

use App\Models\MpesaStkPayments;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;


class MpesaStkService
{
    /**
     * Initiates an STK push request to M-PESA.
     *
     * @param array $stkData Array containing STK push data.
     * @return array response or error message from the API.
     */

    public function lipaNaMpesaStk(array $stkData): array
    {
        try {
            $consumerKey = $stkData['consumer_key'];
            $consumerSecret = $stkData['consumer_secret'];
            $shortCode = $stkData['shortcode'];
            $passkey = $stkData['passkey'];
            $amount = $stkData['amount'];
            $partyA = $stkData['msisdn'];
            $accountReference = $stkData['account_reference'];
            $stkCallbackUrl = $stkData['stk_callback'];
            $partyB = $stkData['organization_code'];
            $transactionType = $stkData['transaction_type'];
            $stkInitiateUrl = env('SAF_STK_URL');


            $password = $this->getPassword($shortCode, $passkey);

            $accessToken = Cache::get('safaricom_stk_access_token');

            if (!$accessToken) {

                $response = $this->getAccessToken($consumerKey, $consumerSecret);

                if (isset($response['error'])) {

                    Log::channel('mpesa')->error('Failed to fetch access token: ' . $response['error']);

                    return $response;
                }

                $accessToken = $response['access_token'];
                $expiry = $response['expires_in'];

                Cache::put('safaricom_stk_access_token', $accessToken, now()->addSeconds($expiry));
            }

            Log::channel('mpesa')->info("STK:Token generated");

            $timestamp = Carbon::now()->format('YmdHis');

            $postData = [
                'BusinessShortCode' => $shortCode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => $transactionType,
                'Amount' => $amount,
                'PartyA' => $partyA,
                'PartyB' => $partyB,
                'PhoneNumber' => $partyA,
                'CallBackURL' => $stkCallbackUrl,
                'AccountReference' => $accountReference,
                'TransactionDesc' => $partyA . " has paid " . $amount . " to " . $shortCode
            ];

            $requestHeaders = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
            ];

            $ch = curl_init();

            curl_setopt_array($ch, array(
                CURLOPT_URL => $stkInitiateUrl,
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $requestHeaders,
                CURLOPT_POSTFIELDS => json_encode($postData)
            ));

            $response = curl_exec($ch);

            Log::channel('mpesa')->info('STK Request initiated');

            if (curl_errno($ch)) {
                $error = 'Error: ' . curl_error($ch);
                curl_close($ch);
                return ['error' => $error];
            }

            curl_close($ch);

            return json_decode($response, true);
        } catch (\Exception $e) {

            $errorMessage = $e->getMessage();

            Log::channel('mpesa')->error('An error occurred: ' . $errorMessage);

            return ['error' => 'An unexpected error occurred: ' . $errorMessage];
        }
    }


    /**
     * Handles the M-PESA STK callback data and saves it to the database.
     *
     * @param array $data An associative array containing M-PESA STK callback data. 
     *                     Expected keys: 'merchant_request_id', 'checkout_request_id', 
     *                     'transaction_id', 'transaction_date', 'amount', 'msisdn'.
     * @return array An associative array indicating the result of the operation. 
     *               Contains either 'success' with a message or 'error' with an error message.
     */
    public function handleStkCallbackData(array $data): array
    {

        $merchantRequestId = $data['merchant_request_id'];
        $checkoutRequestId = $data['checkout_request_id'];
        $transactionId = $data['transaction_id'];
        $transTime = $data['transaction_date'];
        $amount = $data['amount'];
        $msisdn = $data['msisdn'];

        $mpesaStkPayment = MpesaStkPayments::where('merchant_request_id', $merchantRequestId)
            ->where('checkout_request_id', $checkoutRequestId)
            ->first();

        if (!$mpesaStkPayment) {

            Log::channel('mpesa')->error('Record not found for MerchantRequestID: ' . $merchantRequestId . ' and CheckoutRequestID: ' . $checkoutRequestId);

            return ['error' => 'Record not found for ' . $merchantRequestId];
        }

        try {

            $mpesaStkPayment->transaction_id = $transactionId;
            $mpesaStkPayment->transaction_date = $transTime;
            $mpesaStkPayment->amount = $amount;
            $mpesaStkPayment->msisdn = $msisdn;
            $mpesaStkPayment->save();

            return ['success' => 'Entry for ' . $transactionId . ' updated successfully'];
        } catch (\Exception $e) {

            Log::channel('mpesa')->error('Failed to update entry for :' . $transactionId . ' - ' . $e->getMessage());

            return ['error' => 'Failed to update Mpesa callback entry: ' . $e->getMessage()];
        }
    }

    /**
     * Saves STK Payment details to the database.
     *
     * @param array $data Array containing 'MerchantRequestID' and 'CheckoutRequestID'.
     * @return array Success or error message indicating the result of the save operation.
     */
    public function saveStkPayment(array $data,string $shortcode): array
    {
        try {
            MpesaStkPayments::create([
                'merchant_request_id' => $data['MerchantRequestID'],
                'checkout_request_id' => $data['CheckoutRequestID'],
                'shortcode' => $shortcode, 
            ]);

            return ['success' => 'Saved Data for' . $data['CheckoutRequestID']];
        } catch (\Exception $e) {
            Log::channel('mpesa')->error('Error saving STK Payment: ' . $e->getMessage());

            return ['error' => 'Failed to save payment details' . $e->getMessage()];
        }
    }

  



    /**
     * Generates the M-PESA password.
     *
     * @param int $shortCode The short code for the transaction.
     * @param string $passkey The passkey for the transaction.
     * @return string The encoded password.
     */

    private function getPassword(int $shortCode, string $passkey): string
    {

        $timestamp = Carbon::now()->format('YmdHis');

        $password  = base64_encode($shortCode . $passkey . $timestamp);

        return $password;
    }

    /**
     * Fetches the access token from M-PESA.
     *
     * @param string $consumerKey The consumer key for the API.
     * @param string $consumerSecret The consumer secret for the API.
     * @return array The response containing the access token or an error message.
     */


    private function getAccessToken(string $consumerKey, string $consumerSecret): array|JsonResponse
    {

        $url = env('SAF_AUTH_URL');

        $mpesaAuthService = new MpesaAuthService;

        $response = $mpesaAuthService->generateAccessToken($url, $consumerKey, $consumerSecret);

        if (isset($response['error'])) {

            $errorMessage = $response['error'];

            Log::channel('mpesa')->error("STK- Failed to fetch access token: $errorMessage");

            return ['error' => $response['error']];
        }

        return $response;
    }
}
