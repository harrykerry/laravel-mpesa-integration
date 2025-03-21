<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Services\MpesaCallBackService;
use Illuminate\Support\Facades\Validator;
use App\Services\MpesaCallbackRegistrationService;

class MpesaCallbackController extends Controller
{
    //

    protected MpesaCallbackRegistrationService $mpesaCallbackRegistration;
    protected MpesaCallBackService $mpesaCallBackService;

    public function __construct(MpesaCallbackRegistrationService $mpesaCallbackRegistration, MpesaCallBackService $mpesaCallBackService)
    {
        $this->mpesaCallbackRegistration = $mpesaCallbackRegistration;
        $this->mpesaCallBackService = $mpesaCallBackService;
    }

    /**
     * Handle the registration of M-PESA callback URLs.
     *
     * @param \Illuminate\Http\Request $request The incoming request containing callback URL data.
     * 
     * @return \Illuminate\Http\JsonResponse A JSON response with the result of the registration.
     */


    public function registerCallback(Request $request): JsonResponse
    {


        $requestData = Validator::make($request->all(), [
            'confirmation_url' => 'required|url',
            'validation_url' => 'required|url',
            'consumer_key' => 'required|string',
            'consumer_secret' => 'required|string',
            'shortcode' => 'required|numeric'
        ]);

        if ($requestData->fails()) {
            return response()->json($requestData->errors(), 422);
        }

        $callbackUrlData = $requestData->validated();

        $response = $this->mpesaCallbackRegistration->registerCallBackUrl($callbackUrlData);

        if ($response['status'] === 'error') {

            return response()->json($response, 500);
        }

        return response()->json($response, 200);
    }


    /**
     * Handles the M-PESA C2B callback data sent by Mpesa
 
     * @param \Illuminate\Http\Request $request The incoming HTTP request containing the callback data.
     * 
     * @return \Illuminate\Http\JsonResponse The response from the `MpesaCallBackService` after processing the callback data.
     */

    public function handlec2bCallback(Request $request): JsonResponse
    {

        $mpesaCallbackData = $request->all();

        $response = $this->mpesaCallBackService->handleCallBackData($mpesaCallbackData);

        if ($response['status'] === 'error') {

            return response()->json($response, 500);
        }

        return response()->json($response, 200);
    }


    /**
     * Handles the M-PESA C2B validation callback. Can perform further validation logic customized for specific use cases
 
     * @param \Illuminate\Http\Request $request The incoming HTTP request containing the validation data.
     * 
     * @return \Illuminate\Http\JsonResponse A JSON response indicating that the validation was accepted.
     */

    public function handlec2bvalidation(Request $request): JsonResponse
    {

        $mpesaCallbackData = $request->all(); //retrieve and pass to a service for any further custom logic before returing the response

        $response = [
            "ResultCode" => "0",  //use ResultCode C2B00011 and ResultDesc Rejected to reject transactions
            "ResultDesc" => "Accepted"

        ];

        return response()->json($response, 200);
    }
}
