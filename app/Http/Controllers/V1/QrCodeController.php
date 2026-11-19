<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\MpesaQrService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Generates the M-PESA QR codes a customer scans to pay.
 */
class QrCodeController extends Controller
{
    public function __construct(protected readonly MpesaQrService $qr) {}

    /**
     * Generate a QR code for one payment.
     */
    public function generate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:1'],
            'reference' => ['required', 'string', 'max:40'],
            'transaction_type' => ['sometimes', 'string', Rule::in(MpesaQrService::TRANSACTION_TYPES)],
            'size' => ['sometimes', 'integer', 'min:100', 'max:1000'],
        ], [
            'reference.required' => 'A reference is required: it is what the customer sees the payment is for.',
            'transaction_type.in' => 'The transaction type must be one of: '.implode(', ', MpesaQrService::TRANSACTION_TYPES).'.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator);
        }

        $data = $validator->validated();
        $qr = $this->qr->generateQrCode(
            $data['amount'],
            $data['reference'],
            $data['transaction_type'] ?? 'PB',
            size: $data['size'] ?? 300,
        );

        return ApiResponse::success($qr, $qr['mpesa_description']);
    }
}
