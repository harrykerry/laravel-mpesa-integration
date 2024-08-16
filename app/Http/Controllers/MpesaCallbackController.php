<?php

namespace App\Http\Controllers;

use App\Services\MpesaCallBackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MpesaCallbackController extends Controller
{
    //
  

 /**
 * Handles the M-PESA C2B callback.
 
 * @param \Illuminate\Http\Request $request The incoming HTTP request containing the callback data.
 * 
 * @return \Illuminate\Http\JsonResponse The response from the `MpesaCallBackService` after processing the callback data.
 */

    public function handlec2bCallback(Request $request)
    {

        Log::channel('app')->info('CallBack_Initiated: ' . json_encode($request->all()));

        $mpesaData = $request->all();

        $mpesaCallBackService = new MpesaCallBackService();

        $response = $mpesaCallBackService->handleCallBackData($mpesaData);

        return $response;
    }


 /**
 * Handles the M-PESA C2B validation callback.
 
 * @param \Illuminate\Http\Request $request The incoming HTTP request containing the validation data.
 * 
 * @return \Illuminate\Http\JsonResponse A JSON response indicating that the validation was accepted.
 */

    public function hanclec2bvalidation(Request $request)
    {

        Log::channel('app')->info('Validation_Initiated: ' . json_encode($request->all()));

        $response = [

            "ResultCode" => "0",
            "ResultDesc" => "Accepted"

        ];

        return response()->json($response, 200);
    }
}
