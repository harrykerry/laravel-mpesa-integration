<?php

namespace App\Http\Controllers\V1\Callbacks;

use App\Enums\MpesaCallbackType;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessStkCallback;
use App\Services\MpesaCallbackInbox;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives the callback M-PESA sends once an STK push is resolved.
 */
class StkCallbackController extends Controller
{
    public function __construct(protected readonly MpesaCallbackInbox $inbox) {}

    /**
     * Take in the outcome of a push, paid or not.
     *
     * The payload is recorded, a queued job matches it to the push and
     * records the outcome, and M-PESA is acknowledged straight away.
     */
    public function receiveCallback(Request $request): JsonResponse
    {
        $callback = $this->inbox->recordIncomingCallback(
            MpesaCallbackType::Stk,
            $request->all(),
            $request->ip(),
        );

        if ($callback->wasRecentlyCreated) {
            ProcessStkCallback::dispatch($callback);
        }

        return ApiResponse::acknowledge();
    }
}
