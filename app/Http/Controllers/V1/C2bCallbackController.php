<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\MpesaCallBackService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives M-PESA C2B validation and confirmation traffic.
 */
class C2bCallbackController extends Controller
{
    public function __construct(protected readonly MpesaCallBackService $callbacks) {}

    /**
     * Accept or reject a C2B payment before M-PESA completes it.
     *
     * Answer with ResultCode C2B00011 and "Rejected" to turn a payment away;
     * anything else M-PESA treats as accepted.
     */
    public function validation(Request $request): JsonResponse
    {
        return ApiResponse::acknowledge();
    }

    /**
     * Store a completed C2B payment M-PESA has confirmed.
     */
    public function confirmation(Request $request): JsonResponse
    {
        $this->callbacks->handleCallBackData($request->all());

        return ApiResponse::acknowledge();
    }
}
