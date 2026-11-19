<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\MpesaStkService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Asks M-PESA what became of an STK push.
 *
 * Useful when a callback never arrived, or while a frontend waits for the
 * customer to answer the prompt.
 */
class StkStatusController extends Controller
{
    public function __construct(protected readonly MpesaStkService $stk) {}

    /**
     * Look a push up by the id the push request returned.
     */
    public function checkStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'checkout_request_id' => ['required', 'string', 'max:60'],
        ], [
            'checkout_request_id.required' => 'The checkout_request_id from the push response is required.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator);
        }

        $status = $this->stk->checkPushStatus($validator->validated()['checkout_request_id']);

        return ApiResponse::success($status, $status['mpesa_description']);
    }
}
