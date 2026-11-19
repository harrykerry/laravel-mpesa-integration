<?php

namespace App\Http\Controllers\V1\Callbacks;

use App\Enums\MpesaCallbackType;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessMpesaResult;
use App\Services\MpesaCallbackInbox;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives the outcome of the asynchronous Daraja requests.
 *
 * Transaction status, account balance, reversal, B2C, B2B and tax
 * remittance all post their outcome here, or to the timeout endpoint when
 * M-PESA's queue gives up on them.
 */
class ResultCallbackController extends Controller
{
    public function __construct(protected readonly MpesaCallbackInbox $inbox) {}

    /**
     * Take in the outcome of a request M-PESA has finished processing.
     */
    public function receiveResult(Request $request): JsonResponse
    {
        return $this->queue(MpesaCallbackType::Result, $request);
    }

    /**
     * Take in a request M-PESA's queue timed out on.
     */
    public function receiveTimeout(Request $request): JsonResponse
    {
        return $this->queue(MpesaCallbackType::Timeout, $request);
    }

    /**
     * Record the payload, queue the work and acknowledge M-PESA.
     */
    protected function queue(MpesaCallbackType $type, Request $request): JsonResponse
    {
        $callback = $this->inbox->recordIncomingCallback($type, $request->all(), $request->ip());

        if ($callback->wasRecentlyCreated) {
            ProcessMpesaResult::dispatch($callback);
        }

        return ApiResponse::acknowledge();
    }
}
