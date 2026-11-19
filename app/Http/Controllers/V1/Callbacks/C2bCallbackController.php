<?php

namespace App\Http\Controllers\V1\Callbacks;

use App\Enums\MpesaCallbackType;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessC2bConfirmation;
use App\Services\MpesaC2bValidationService;
use App\Services\MpesaCallbackInbox;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives the C2B traffic M-PESA sends to this application.
 *
 * Nobody initiates a C2B payment here: a customer pays the shortcode
 * directly through M-PESA, and Safaricom calls these two endpoints.
 */
class C2bCallbackController extends Controller
{
    public function __construct(
        protected readonly MpesaCallbackInbox $inbox,
        protected readonly MpesaC2bValidationService $validation,
    ) {}

    /**
     * Decide whether M-PESA may complete a payment it is holding.
     *
     * This is the one callback M-PESA waits on, so the decision is made
     * inline rather than queued. Accepting deducts the customer and brings a
     * confirmation; rejecting leaves the customer's money alone. The rules
     * themselves live in MpesaC2bValidationService::checks().
     */
    public function validatePayment(Request $request): JsonResponse
    {
        $payload = $request->all();

        $callback = $this->inbox->recordIncomingCallback(
            MpesaCallbackType::C2bValidation,
            $payload,
            $request->ip(),
        );

        $rejection = $this->validation->decide($payload);

        $callback->markProcessed();

        return $rejection === null
            ? ApiResponse::acceptPayment()
            : ApiResponse::rejectPayment($rejection);
    }

    /**
     * Take in a payment M-PESA has completed.
     *
     * The payload is recorded, a queued job stores the payment, and M-PESA
     * is acknowledged straight away so it never waits on our database.
     */
    public function receiveConfirmation(Request $request): JsonResponse
    {
        $callback = $this->inbox->recordIncomingCallback(
            MpesaCallbackType::C2bConfirmation,
            $request->all(),
            $request->ip(),
        );

        if ($callback->wasRecentlyCreated) {
            ProcessC2bConfirmation::dispatch($callback);
        }

        return ApiResponse::acknowledge();
    }
}
