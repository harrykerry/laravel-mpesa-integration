<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MpesaConfirmation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MpesaDataFetchController extends Controller
{
    //


 /**
 * Fetches Mpesa confirmation records that have not been previously fetched.
 *
 * @param \Illuminate\Http\Request $request The incoming HTTP request.
 * 
 * @return \Illuminate\Http\JsonResponse A JSON response containing the fetched records or an error message.
 * 
 * @throws \Exception If an error occurs while trying to fetch the data from the database.
 */

    public function fetchMpesaData(Request $request)
    {
        try {
            $cacheKey = 'fetched_record_ids';

            $lastFetchedId = Cache::get($cacheKey, 0);

            $records = MpesaConfirmation::where('id', '>', $lastFetchedId)
                ->get(['mobile_number', 'first_name', 'business_shortcode', 'transaction_id']);

            if ($records->isNotEmpty()) {

                $newLastFetchedId = $records->max('id');

                Cache::put($cacheKey, $newLastFetchedId);
            }

            Log::channel('mpesa')->info('Fetch_Initiated - ' - $records->max('id'));
            return response()->json($records,200);

        } catch (\Exception $e) {

            $errorMessage = $e->getMessage();

            Log::channel('mpesa')->error('Error fetching record above' . $records->max('id') . $errorMessage);

            return response()->json($errorMessage, 500);
        }
    }
}
