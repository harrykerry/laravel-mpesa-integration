<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\MpesaStkPayments;
use App\Traits\MobileFormattingTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;


class MpesaStkService
{

    use MobileFormattingTrait;



    /**
     * Initiates an STK push request.
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


            $passwordValues = $this->getPassword($shortCode, $passkey);

            $password = $passwordValues['password'];
            $timestamp = $passwordValues['timestamp'];

            $accessToken = Cache::get('safaricom_stk_access_token');

            if (!$accessToken) {

                $response = $this->getAccessToken($consumerKey, $consumerSecret);

                if (isset($response['status']) && $response['status'] === 'error') {
                    return $response;
                }

                $accessToken = $response['access_token'];
                $expiry = $response['expires_in'];

                Cache::put('safaricom_stk_access_token', $accessToken, now()->addSeconds($expiry));
            }

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

            if (curl_errno($ch)) {

                $errorMessage = curl_error($ch);

                Log::channel('mpesa')->error("STK_ERROR:" . $errorMessage);

                curl_close($ch);

                return [
                    'status' => 'error',
                    'message' => $errorMessage
                ];
            }

            $responseBody = json_decode($response, true);
            Log::channel('mpesa')->info("STK: Request initiated", $responseBody);

            curl_close($ch);

            if (isset($responseBody['ResponseCode']) && $responseBody['ResponseCode'] === '0') {

                $this->saveStkPayment($responseBody, $shortCode);

                return [
                    'status' => 'success',
                    'message' => $responseBody

                ];
            }

            return [
                'status' => 'error',
                'message' => "STK error: " . json_encode($responseBody),
            ];
        } catch (\Exception $e) {

            $errorMessage = $e->getMessage();

            Log::channel('mpesa')->error("STK_ERROR: $errorMessage");


            return [
                'status' => 'error',
                'message' => $errorMessage
            ];
        }
    }


    /**
     * Handles the M-PESA STK callback data and saves it to the database.
     *
     * @param array $data An associative array containing M-PESA STK callback data. 
     *                     Expected keys: 'merchant_request_id', 'checkout_request_id', 
     *                     'transaction_id', 'transaction_date', 'amount', 'msisdn'.
     * @return array An associative array indicating the result of the operation. 
     */
    public function handleStkCallbackData(array $callbackData): array
    {

        $merchantRequestId = $callbackData['MerchantRequestID'];
        $checkoutRequestId = $callbackData['CheckoutRequestID'];
        $resultCode = $callbackData['ResultCode'];
        $resultDesc = $callbackData['ResultDesc'];

        if ($resultCode !== 0) {

            Log::channel('mpesa')->error("STK_CALLBACK_ERROR - Failed with code $resultCode - $resultDesc");

            return [
                'status' => 'error',
                'message' => $resultDesc
            ];
        }

        $callbackMetadata = $callbackData['CallbackMetadata']['Item'];

        $amount = null;
        $transactionDate = null;
        $msisdn = null;
        $transactionId = null;

        foreach ($callbackMetadata as $item) {
            switch ($item['Name']) {
                case 'Amount':
                    $amount = $item['Value'];
                    break;
                case 'MpesaReceiptNumber':
                    $transactionId = $item['Value'];
                    break;
                case 'TransactionDate':
                    $transactionDate = $item['Value'];
                    break;
                case 'PhoneNumber':
                    $msisdn = $this->sanitizeAndFormatMobile($item['Value']);
                    break;
                default:
                    break;
            }
        }


        $mpesaStkPayment = MpesaStkPayments::where('merchant_request_id', $merchantRequestId)
            ->where('checkout_request_id', $checkoutRequestId)
            ->first();

        if (!$mpesaStkPayment) {

            Log::channel('mpesa')->error("STK_CALLBACK_ERROR: Record was not saved initially for $merchantRequestId  and CheckoutRequestID $checkoutRequestId");

            return [
                'status' => 'error',
                'message' => "Record not found for $merchantRequestId"
            ];
        }

        try {

            $mpesaStkPayment->transaction_id = $transactionId;
            $mpesaStkPayment->transaction_date = $transactionDate;
            $mpesaStkPayment->amount = $amount;
            $mpesaStkPayment->msisdn = $msisdn;
            $mpesaStkPayment->save();

            return [
                'status' => 'success',
                'message' => "Entry for $transactionId updated successfully"
            ];
        } catch (\Exception $e) {

            Log::channel('mpesa')->error("STK_CALLBACK_ERROR: Failed to update entry for {$transactionId} {$e->getMessage()}");

            return [
                'status' => 'error',
                'message' => "Failed to update Mpesa callback entry: {$e->getMessage()}"

            ];
        }
    }

    /**
     * Saves STK Payment details to the database.
     *
     * @param array $data Array containing 'MerchantRequestID' and 'CheckoutRequestID'.
     * @return void
     * @throws \Exception If saving fails.
     */
    private function saveStkPayment(array $data, string $shortcode): void
    {
        try {
            MpesaStkPayments::create([
                'merchant_request_id' => $data['MerchantRequestID'],
                'checkout_request_id' => $data['CheckoutRequestID'],
                'business_shortcode' => $shortcode,
            ]);

            Log::channel('mpesa')->info("STK: Payment Saved {$data['CheckoutRequestID']}");
        } catch (\Exception $e) {

            Log::channel('mpesa')->error("STK_SAVE_ERROR: {$e->getMessage()}");

            throw new \Exception('Failed to save payment details: ' . $e->getMessage());
        }
    }



    /**
     * Generates the M-PESA password.
     *
     * @param int $shortCode The short code for the transaction.
     * @param string $passkey The passkey for the transaction.
     * @return array The encoded password and timestamp
     */

    private function getPassword(int $shortCode, string $passkey): array
    {

        $timestamp = Carbon::now()->format('YmdHis');

        $password  = base64_encode($shortCode . $passkey . $timestamp);

        return [

            'password' => $password,
            'timestamp' => $timestamp
        ];
    }

    /**
     * Fetches the access token from M-PESA.
     *
     * @param string $consumerKey The consumer key for the API.
     * @param string $consumerSecret The consumer secret for the API.
     * @return array The response containing the access token or an error message.
     */


    private function getAccessToken(string $consumerKey, string $consumerSecret): array
    {

        $mpesaAuthService = new MpesaAuthService;

        $url = env('SAF_AUTH_URL');

        $response = $mpesaAuthService->generateAccessToken($url, $consumerKey, $consumerSecret);

        return $response;
    }
}
