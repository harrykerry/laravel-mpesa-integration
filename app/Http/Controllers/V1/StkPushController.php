<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\MpesaStkService;
use App\Support\ApiResponse;
use App\Support\Msisdn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Initiates STK (Lipa na M-PESA Online) pushes.
 */
class StkPushController extends Controller
{
    public function __construct(protected readonly MpesaStkService $stk) {}

    /**
     * Push a payment request to a customer's handset.
     *
     * A frontend sends only who is paying and how much. The shortcode,
     * passkey, transaction type, account reference and callback URL are
     * deployment configuration, and anything else sent here is ignored.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:1', 'max:250000'],
            'msisdn' => ['required', 'string', Msisdn::RULE],
        ], [
            'amount.required' => 'An amount is required.',
            'amount.numeric' => 'The amount must be a number.',
            'amount.min' => 'The amount must be at least 1 shilling.',
            'amount.max' => 'The amount may not be more than 250,000 shillings.',
            'msisdn.required' => 'A mobile number is required.',
            'msisdn.regex' => 'The mobile number must be a Kenyan number, for example 0712345678.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator);
        }

        $data = $validator->validated();

        return ApiResponse::success(
            data: $this->stk->push($data['amount'], $data['msisdn']),
            message: 'STK push sent',
        );
    }
}
