<?php

namespace App\Http\Controllers;

use App\Services\MpesaStkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Initiates STK pushes and receives their callbacks.
 */
class mpesaStkController extends Controller
{
    public function __construct(protected readonly MpesaStkService $stk) {}

    /**
     * Push a payment request to a customer's handset.
     *
     * The request carries only who is paying and how much. The shortcode,
     * passkey, transaction type, account reference and callback URL are all
     * backend configuration; anything else in the payload is ignored.
     */
    public function initiateStkRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'msisdn' => ['required', 'string', 'regex:/^(?:\+?254|0)?(?:7|1)\d{8}$/'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'STK push sent',
            'data' => $this->stk->push($data),
        ]);
    }

    /**
     * Record the outcome of a push M-PESA has finished processing.
     */
    public function handleStkCallback(Request $request): JsonResponse
    {
        $response = $this->stk->handleStkCallbackData($request->input('Body.stkCallback', []));

        return response()->json($response, $response['status'] === 'error' ? 500 : 200);
    }
}
