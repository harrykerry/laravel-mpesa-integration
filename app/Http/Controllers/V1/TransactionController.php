<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\MpesaTransactionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Asks M-PESA about, or undoes, a transaction.
 *
 * These three Daraja APIs answer twice: the response here carries the
 * conversation id M-PESA accepted, and the outcome arrives later on the
 * result callback.
 */
class TransactionController extends Controller
{
    public function __construct(protected readonly MpesaTransactionService $transactions) {}

    /**
     * Ask M-PESA what became of a transaction.
     */
    public function checkStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'transaction_id' => ['required', 'string', 'max:40'],
            'remarks' => ['sometimes', 'string', 'max:100'],
        ], [
            'transaction_id.required' => 'The M-PESA receipt number is required, for example SGR51YQ9AB.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator);
        }

        $data = $validator->validated();
        $status = $this->transactions->checkTransactionStatus(
            $data['transaction_id'],
            remarks: $data['remarks'] ?? 'Status query',
        );

        return ApiResponse::success($status, $status['mpesa_description']);
    }

    /**
     * Ask M-PESA for the balance of one of our shortcodes.
     */
    public function checkBalance(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shortcode' => ['sometimes', 'numeric'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator);
        }

        $balance = $this->transactions->checkAccountBalance(
            $validator->validated()['shortcode'] ?? null,
        );

        return ApiResponse::success($balance, $balance['mpesa_description']);
    }

    /**
     * Send a payment back to the customer who made it.
     */
    public function reverse(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'transaction_id' => ['required', 'string', 'max:40'],
            'amount' => ['required', 'numeric', 'min:1'],
            'remarks' => ['sometimes', 'string', 'max:100'],
        ], [
            'transaction_id.required' => 'The M-PESA receipt number of the payment to reverse is required.',
            'amount.required' => 'The amount to reverse is required.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator);
        }

        $data = $validator->validated();
        $reversal = $this->transactions->reverseTransaction(
            $data['transaction_id'],
            $data['amount'],
            remarks: $data['remarks'] ?? 'Reversal',
        );

        return ApiResponse::success($reversal, $reversal['mpesa_description']);
    }
}
