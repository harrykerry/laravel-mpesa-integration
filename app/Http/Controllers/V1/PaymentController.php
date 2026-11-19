<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\MpesaDataFetchService;
use App\Support\ApiResponse;
use App\Support\Msisdn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Reads stored M-PESA transactions back out of this application.
 */
class PaymentController extends Controller
{
    public function __construct(protected readonly MpesaDataFetchService $payments) {}

    /**
     * List stored C2B payments.
     *
     * Supports free-text search, filtering by shortcode, payer, receipt,
     * bill reference, date window and amount range, plus sorting and
     * pagination. Every filter is optional.
     */
    public function c2b(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'search' => ['sometimes', 'string', 'min:2', 'max:60'],
            'shortcode' => ['sometimes', 'numeric'],
            'msisdn' => ['sometimes', 'string', Msisdn::RULE],
            'transaction_id' => ['sometimes', 'string', 'max:40'],
            'bill_reference' => ['sometimes', 'string', 'max:40'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'min_amount' => ['sometimes', 'numeric', 'min:0'],
            'max_amount' => ['sometimes', 'numeric', 'min:0', Rule::when($request->has('min_amount'), ['gte:min_amount'])],
            'sort' => ['sometimes', 'string', Rule::in(MpesaDataFetchService::C2B_SORTS)],
            'direction' => ['sometimes', 'string', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ], $this->filterMessages(MpesaDataFetchService::C2B_SORTS));

        if ($validator->fails()) {
            return ApiResponse::invalid($validator, 'The filters are not valid.');
        }

        $payments = $this->payments->c2bPayments($validator->validated());

        return ApiResponse::success(
            data: $payments['data'],
            message: 'C2B payments retrieved',
            meta: $payments['meta'],
        );
    }

    /**
     * List stored STK pushes.
     *
     * Supports free-text search, filtering by payer, shortcode, receipt,
     * either request id, paid or pending status, date window and amount
     * range, plus sorting and pagination. Every filter is optional.
     */
    public function stk(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'search' => ['sometimes', 'string', 'min:2', 'max:60'],
            'msisdn' => ['sometimes', 'string', Msisdn::RULE],
            'shortcode' => ['sometimes', 'numeric'],
            'transaction_id' => ['sometimes', 'string', 'max:40'],
            'merchant_request_id' => ['sometimes', 'string', 'max:60'],
            'checkout_request_id' => ['sometimes', 'string', 'max:60'],
            'status' => ['sometimes', 'string', 'in:paid,pending'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'min_amount' => ['sometimes', 'numeric', 'min:0'],
            'max_amount' => ['sometimes', 'numeric', 'min:0', Rule::when($request->has('min_amount'), ['gte:min_amount'])],
            'sort' => ['sometimes', 'string', Rule::in(MpesaDataFetchService::STK_SORTS)],
            'direction' => ['sometimes', 'string', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ], $this->filterMessages(MpesaDataFetchService::STK_SORTS) + [
            'status.in' => 'The status must be either paid or pending.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator, 'The filters are not valid.');
        }

        $payments = $this->payments->stkPayments($validator->validated());

        return ApiResponse::success(
            data: $payments['data'],
            message: 'STK payments retrieved',
            meta: $payments['meta'],
        );
    }

    /**
     * Messages shared by both payment lists.
     *
     * @param  list<string>  $sortable
     * @return array<string, string>
     */
    protected function filterMessages(array $sortable): array
    {
        return [
            'search.min' => 'The search term must be at least 2 characters.',
            'msisdn.regex' => 'The mobile number must be a Kenyan number, for example 0712345678.',
            'from.date' => 'The from date must be a valid date, for example 2026-11-01.',
            'to.date' => 'The to date must be a valid date, for example 2026-11-30.',
            'to.after_or_equal' => 'The to date must not be before the from date.',
            'max_amount.gte' => 'The maximum amount must not be below the minimum amount.',
            'sort.in' => 'The list can be sorted by: '.implode(', ', $sortable).'.',
            'direction.in' => 'The direction must be either asc or desc.',
            'per_page.max' => 'At most 200 records can be returned per page.',
        ];
    }
}
