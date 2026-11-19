<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\MpesaStkService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives the callback M-PESA sends once a push is resolved.
 */
class StkCallbackController extends Controller
{
    public function __construct(protected readonly MpesaStkService $stk) {}

    /**
     * Record the outcome M-PESA reports for a push.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $this->stk->handleStkCallbackData($request->input('Body.stkCallback', []));

        return ApiResponse::acknowledge();
    }
}
