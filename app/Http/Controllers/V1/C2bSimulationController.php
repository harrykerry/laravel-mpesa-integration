<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\MpesaC2bService;
use App\Support\ApiResponse;
use App\Support\Msisdn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Pretends a customer paid the shortcode, in the sandbox only.
 *
 * This is how the validation and confirmation flow is exercised without a
 * real handset. Safaricom does not offer it in production, where a C2B
 * payment can only start with the customer.
 */
class C2bSimulationController extends Controller
{
    public function __construct(protected readonly MpesaC2bService $c2b) {}

    /**
     * Simulate a customer payment.
     */
    public function simulate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:1'],
            'msisdn' => ['required', 'string', Msisdn::RULE],
            'bill_reference' => ['sometimes', 'string', 'max:40'],
        ], [
            'msisdn.regex' => 'The mobile number must be a Kenyan number, for example 0712345678.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator);
        }

        $data = $validator->validated();
        $simulation = $this->c2b->simulateCustomerPayment(
            $data['amount'],
            $data['msisdn'],
            $data['bill_reference'] ?? 'TEST',
        );

        return ApiResponse::success($simulation, $simulation['mpesa_description']);
    }
}
