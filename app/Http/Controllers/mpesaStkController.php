<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\MpesaStkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class mpesaStkController extends Controller
{
    //

    /**
     * Initiates an M-PESA STK transaction.
     *
     * @param Request $request The incoming request containing STK data.
     * @return \Illuminate\Http\JsonResponse JSON response with API result or error message.
     */

    public function initiateStkRequest(Request $request)
    {

        $validatedData = $request->validate([
            'consumer_key' => 'required|string',
            'consumer_secret' => 'required|string',
            'shortcode' => 'required|string',
            'passkey' => 'required|string',
            'amount' => 'required|numeric',
            'msisdn' => 'required|string',
            'account_reference' => 'required|string',
            'stk_callback' => 'required|string',
            'organization_code' => 'required|string', //till or paybill
            'transaction_type' => 'required|string', //use CustomerBuyGoodsOnline for paybill and CustomerPayBillOnline for til
        ]);


        $mpesaStkService = new MpesaStkService;

        $response = $mpesaStkService->lipaNaMpesaStk($validatedData);

        if (isset($response['error'])) {

            return response()->json([
                'status' => 'error',
                'message' => $response['error']
            ], 500);
        }


        if (isset($response['ResponseCode']) && $response['ResponseCode'] === '0') {

            Log::channel('mpesa')->info('STK Request successful: ' . json_encode($response));

            $result = $mpesaStkService->saveStkPayment($response);


            if (isset($result['error'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => $result['error']
                ], 500);
            }

            return response()->json([
                'status' => 'success',
                'message' => $response['ResponseDescription'],
                'data' => $response
            ], 200);
        }

        Log::channel('mpesa')->error('STK Request failed: ' . json_encode($response));

        return response()->json([
            'status' => 'error',
            'message' => $response['ResponseDescription'] ?? 'An error occurred',
            'data' => $response
        ], 500);
    }


    /**
     * Handle M-PESA STK callback data.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */

    public function handleStkCallback(Request $request)
    {

        Log::channel('mpesa')->info('Received STK Callback data: ' . json_encode($request->all()));

        $callbackData = $request->input('Body.stkCallback', []);

        
        $merchantRequestId = $callbackData['MerchantRequestID'];
        $checkoutRequestId = $callbackData['CheckoutRequestID'];
        $resultCode = $callbackData['ResultCode'];
        $resultDesc = $callbackData['ResultDesc'];

        if ($resultCode !== 0) {
            Log::channel('mpesa')->error('STK Callback failed with ResultCode: ' . $resultCode . ' - ' . $resultDesc);
            return response()->json(['status' => 'error', 'message' => $resultDesc], 500);
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
                    $msisdn = $item['Value'];
                    break;
                default:
                    //Do nothing
                    break;
            }
        }


        $data = [
            'merchant_request_id' => $merchantRequestId,
            'checkout_request_id' => $checkoutRequestId,
            'transaction_id' => $transactionId,
            'transaction_date' => $transactionDate,
            'amount' => $amount,
            'msisdn' => $msisdn,
        ];

        $mpesaStkService = new MpesaStkService();

        $response = $mpesaStkService->handleStkCallbackData($data);

        if (isset($response['error'])) {
            Log::channel('mpesa')->error('STK Callback handling failed: ' . $response['error']);

            return response()->json([
                'status' => 'error',
                'message' => $response['error']
            ], 500);
        }

        Log::channel('mpesa')->info('STK Callback handled successfully: ' . json_encode($response));

        return response()->json([
            'status' => 'success',
            'message' => $response['success']
        ], 200);
    }
}
