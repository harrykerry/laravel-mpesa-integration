<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MpesaStkPayments;
use App\Models\MpesaConfirmation;
use App\Services\MpesaDataFetchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class MpesaDataFetchController extends Controller
{
    //

    protected MpesaDataFetchService $mpesaDataFetchService;


    public function __construct(MpesaDataFetchService $mpesaDataFetchService)
    {
        $this->mpesaDataFetchService = $mpesaDataFetchService;
    }

    /**
     * Fetch stored C2B transactions. 
     * @param \Illuminate\Http\Request $request The incoming HTTP request.
     * 
     * @return \Illuminate\Http\JsonResponse A JSON response containing the fetched records or an error message.
     * 
     */

    public function fetchC2bPayments(Request $request): JsonResponse
    {

        /**
         * Implement your own validation logic as this fetch needs to be within a restrcition publicly.
         * This example fetches by business shortcode. You an modify the fetch param  to work for you
         * 
         **/

        $requestData = Validator::make($request->all(), [
            'shortcode' => 'required|numeric'
        ]);

        if ($requestData->fails()) {

            return response()->json($requestData->errors()->first(), 422);
        }

        $shortcode = $requestData['shortcode'];

        $response = $this->mpesaDataFetchService->fetchPayments($shortcode);

        if ($response['status'] === 'error') {
            return response()->json($response, 500);
        }

        return response()->json($response, 200);
    }


    /**
     * Fetch all successful M-PESA STK transactions for specific mobile number
     * @param \Illuminate\Http\Request $request The incoming HTTP request.
     * @return \Illuminate\Http\JsonResponse 
     */

    public function fetchStkPayments(Request $request): JsonResponse
    {

        /**
         * Implement your own validation logic as this fetch needs to be within a restrcition publicly.
         * This example fetches by mobile number. You an modify the fetch param  to work for you
         * 
         **/

        $requestData = Validator::make($request->all(), [
            'mobile' => 'required|numeric|digits_between:10,12'
        ]);

        if ($requestData->fails()) {

            return response()->json($requestData->errors()->first(), 422);
        }

        $msisdn = $requestData['mobile'];

        $response = $this->mpesaDataFetchService->fetchStkPayments($msisdn);

        if ($response['status'] === 'error') {
            return response()->json($response, 500);
        }

        return response()->json($response, 200);
    }
}
