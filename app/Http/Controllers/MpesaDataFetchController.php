<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MpesaStkPayments;
use App\Models\MpesaConfirmation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MpesaDataFetchController extends Controller
{
    //

    // You can implement API authentication here to secure the fetch operation. 

    //You can also modify the fetch, mine was for a specific system.


    /**
     * Fetches Mpesa confirmation records that have not been previously fetched.
     *
     * @param \Illuminate\Http\Request $request The incoming HTTP request.
     * 
     * @return \Illuminate\Http\JsonResponse A JSON response containing the fetched records or an error message.
     * 
     */

    public function fetchC2bPayments(Request $request)
    {


        try {

            $shortcode = $request->query('shortcode');

            $validatedData = $request->validate([
                'shortcode' => 'required|numeric|regex:/^[0-9]+$/'
            ]);

            $shortcode = $validatedData['shortcode'];


            $cacheKey = 'fetched_record_ids';

            $lastFetchedId = Cache::get($cacheKey, 0);

            $records = MpesaConfirmation::where('id', '>', $lastFetchedId)
                ->where('business_shortcode', $shortcode)
                ->get(['id','transaction_type', 'transaction_id', 'transaction_amount', 'business_shortcode','mobile_number', 'first_name']);

            if ($records->isNotEmpty()) {

                $newLastFetchedId = $records->max('id');

                Cache::put($cacheKey, $newLastFetchedId);

                Log::channel('mpesa')->info('C2B_Data_Fetch_Initiated:  ' . $records->max('id'));

                return response()->json([
                    'status' => 'success',
                    'data' => $records
                ], 200);
            }

            Log::channel('mpesa')->info('C2B_Data_Fetch_Initiated: No records found for' . $shortcode);

            return response()->json([
                'status' => 'success',
                'message' => 'No records found',
                'data' => []
            ], 200);
        } catch (\Exception $e) {

            $errorMessage = $e->getMessage();

            Log::channel('mpesa')->error('Error fetching records: ' . $errorMessage);

            return response()->json([
                'status' => 'error',
                'message' => $errorMessage
            ], 500);
        }
    }


    /**
     * Fetch all M-PESA STK payments from the database.
     *
     * @return array
     */

    public function fetchStkPayments(Request $request)
    {
        try {

            $shortcode = $request->query('shortcode');

            $validatedData = $request->validate([
                'shortcode' => 'required|numeric|regex:/^[0-9]+$/'
            ]);

            $shortcode = $validatedData['shortcode'];

            $records = MpesaStkPayments::where('business_shortcode', $shortcode)
                ->get();


            if ($records->isNotEmpty()) {
                Log::channel('mpesa')->info('STK_Data_Fetch_Initiated:  ' . $shortcode);
                return response()->json([
                    'status' => 'success',
                    'data' => $records
                ], 200);
            } else {
                Log::channel('mpesa')->info('STK_Data_Fetch_Initiated: No records found for: ' . $shortcode);

                return response()->json([
                    'status' => 'success',
                    'message' => 'No records found',
                    'data' => []
                ], 200);
            }
        } catch (\Exception $e) {
            Log::channel('mpesa')->error('Error fetching STK payments: ' . $e->getMessage());

            $errorMessage = $e->getMessage();
            return response()->json([
                'status' => 'error',
                'message' => $errorMessage
            ], 500);
        }
    }
}
