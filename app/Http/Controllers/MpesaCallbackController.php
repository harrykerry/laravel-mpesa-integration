<?php

namespace App\Http\Controllers;

use App\Services\MpesaCallbackRegistrationService;
use App\Services\MpesaCallBackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives M-PESA C2B traffic and registers the URLs it is sent to.
 */
class MpesaCallbackController extends Controller
{
    public function __construct(
        protected readonly MpesaCallbackRegistrationService $registration,
        protected readonly MpesaCallBackService $callbacks,
    ) {}

    /**
     * Register this application's confirmation and validation URLs with M-PESA.
     *
     * Credentials and the shortcode come from configuration. The request may
     * only override the URLs, the shortcode and the response type.
     */
    public function registerCallback(Request $request): JsonResponse
    {
        $overrides = $request->validate([
            'shortcode' => ['sometimes', 'numeric'],
            'confirmation_url' => ['sometimes', 'url:https'],
            'validation_url' => ['sometimes', 'url:https'],
            'response_type' => ['sometimes', 'string', 'in:Completed,Cancelled'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Callback URLs registered',
            'data' => $this->registration->registerUrls($overrides),
        ]);
    }

    /**
     * Store a completed C2B payment M-PESA has confirmed.
     */
    public function handleC2bCallback(Request $request): JsonResponse
    {
        $response = $this->callbacks->handleCallBackData($request->all());

        return response()->json($response, $response['status'] === 'error' ? 500 : 200);
    }

    /**
     * Accept or reject a C2B payment before M-PESA completes it.
     *
     * Return ResultCode C2B00011 with "Rejected" to turn a payment away;
     * anything else M-PESA treats as accepted.
     */
    public function handleC2bValidation(Request $request): JsonResponse
    {
        return response()->json([
            'ResultCode' => '0',
            'ResultDesc' => 'Accepted',
        ]);
    }
}
