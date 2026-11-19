<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\MpesaPayoutService;
use App\Support\ApiResponse;
use App\Support\Msisdn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Moves money out of our shortcode.
 *
 * The initiator, its security credential and the paying shortcode are
 * configuration. A caller chooses only who is paid and how much. Each of
 * these answers twice: the response here is M-PESA's acknowledgement, and
 * the outcome arrives later on the result callback.
 */
class PayoutController extends Controller
{
    public function __construct(protected readonly MpesaPayoutService $payouts) {}

    /**
     * Pay a customer (B2C).
     */
    public function payCustomer(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:1', 'max:500000'],
            'msisdn' => ['required', 'string', Msisdn::RULE],
            'remarks' => ['sometimes', 'string', 'max:100'],
            'command_id' => ['sometimes', 'string', 'in:BusinessPayment,SalaryPayment,PromotionPayment'],
        ], [
            'msisdn.regex' => 'The mobile number must be a Kenyan number, for example 0712345678.',
            'command_id.in' => 'The payment type must be BusinessPayment, SalaryPayment or PromotionPayment.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator);
        }

        $data = $validator->validated();
        $payout = $this->payouts->sendToCustomer(
            $data['amount'],
            $data['msisdn'],
            $data['remarks'] ?? 'Payment',
            $data['command_id'] ?? null,
        );

        return ApiResponse::success($payout, $payout['mpesa_description']);
    }

    /**
     * Pay another business (B2B).
     */
    public function payBusiness(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:1'],
            'shortcode' => ['required', 'numeric'],
            'account_reference' => ['required', 'string', 'max:40'],
            'remarks' => ['sometimes', 'string', 'max:100'],
            'command_id' => ['sometimes', 'string', 'in:BusinessPayBill,BusinessBuyGoods,BusinessToBusinessTransfer,MerchantToMerchantTransfer'],
        ], [
            'shortcode.required' => "The receiving business's paybill or till number is required.",
            'account_reference.required' => 'The account to credit at the receiving business is required.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator);
        }

        $data = $validator->validated();
        $payout = $this->payouts->payBusiness(
            $data['amount'],
            (string) $data['shortcode'],
            $data['account_reference'],
            $data['remarks'] ?? 'Business payment',
            $data['command_id'] ?? null,
        );

        return ApiResponse::success($payout, $payout['mpesa_description']);
    }

    /**
     * Remit tax to the KRA.
     */
    public function remitTax(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:1'],
            'payment_registration_number' => ['required', 'string', 'max:40'],
            'remarks' => ['sometimes', 'string', 'max:100'],
        ], [
            'payment_registration_number.required' => 'The PRN from iTax is required.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator);
        }

        $data = $validator->validated();
        $payout = $this->payouts->remitTax(
            $data['amount'],
            $data['payment_registration_number'],
            $data['remarks'] ?? 'Tax remittance',
        );

        return ApiResponse::success($payout, $payout['mpesa_description']);
    }
}
