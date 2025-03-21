<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\MpesaStkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Traits\MobileFormattingTrait;
use Illuminate\Support\Facades\Validator;

class mpesaStkController extends Controller
{
    //

    protected MpesaStkService $mpesaStkService;

    use MobileFormattingTrait;

    public function __construct(MpesaStkService $mpesaStkService)
    {
        $this->mpesaStkService = $mpesaStkService;
    }


    /**
     * Initiates an M-PESA STK request.
     *
     * @param Request $request The incoming request containing STK data.
     * @return \Illuminate\Http\JsonResponse JSON response with API result or error message.
     */

    public function initiateStkRequest(Request $request): JsonResponse
    {

        $requestData = Validator::make($request->all(), [

            'consumer_key' => 'required|string',
            'consumer_secret' => 'required|string',
            'shortcode' => 'required|string',
            'passkey' => 'required|string',
            'amount' => 'required|numeric',
            'msisdn' => 'required|numeric',
            'account_reference' => 'required|string',
            'stk_callback' => 'required|string',
            'organization_code' => 'required|string', //till or paybill
            'transaction_type' => 'required|string', //use CustomerBuyGoodsOnline for paybill and CustomerPayBillOnline for till

        ]);

        if ($requestData->fails()) {
            return response()->json($requestData->errors()->first(), 422);
        }

        $stkData = $requestData->validated();

        $stkData['msisdn'] = $this->sanitizeAndFormatMobile($stkData['msisdn']);


        $response = $this->mpesaStkService->lipaNaMpesaStk($stkData);

        if ($response['status'] === 'error') {

            return response()->json($response, 500);
        }

        return response()->json($response, 200);
    }


    /**
     * Handle M-PESA STK callback data.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */

    public function handleStkCallback(Request $request): JsonResponse
    {

        $callbackData = $request->input('Body.stkCallback', []);

        $response = $this->mpesaStkService->handleStkCallbackData($callbackData);

        if ($response['status'] === 'error') {

            return response()->json($response, 500);
        }

        return response()->json($response, 200);
    }
}
