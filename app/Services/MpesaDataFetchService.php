<?php

namespace App\Services;

use App\Models\MpesaConfirmation;
use App\Models\MpesaStkPayment;
use App\Support\Msisdn;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Reads stored M-PESA transactions back out of the database.
 *
 * Every list is searchable, filterable, sortable and paginated. No filter is
 * required, and an empty result is an empty page rather than an error. The
 * service returns the rows already shaped for the API, with the pagination
 * metadata beside them.
 */
class MpesaDataFetchService
{
    /**
     * Columns a caller may sort each list by.
     */
    public const C2B_SORTS = ['transaction_time', 'transaction_amount', 'created_at', 'id'];

    public const STK_SORTS = ['created_at', 'amount', 'transaction_date', 'id'];

    /**
     * Stored C2B confirmations, newest first by default.
     *
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function c2bPayments(array $filters = []): array
    {
        $query = MpesaConfirmation::query()
            ->when(filled($filters['search'] ?? null), fn (Builder $query) => $query->search((string) $filters['search']))
            ->when(filled($filters['shortcode'] ?? null), fn (Builder $query) => $query->forShortcode($filters['shortcode']))
            ->when(filled($filters['msisdn'] ?? null), fn (Builder $query) => $query->forMsisdn(Msisdn::normalize((string) $filters['msisdn'])))
            ->when(filled($filters['transaction_id'] ?? null), fn (Builder $query) => $query->where('transaction_id', $filters['transaction_id']))
            ->when(filled($filters['bill_reference'] ?? null), fn (Builder $query) => $query->forBillReference((string) $filters['bill_reference']))
            ->transactedBetween($this->date($filters['from'] ?? null), $this->date($filters['to'] ?? null))
            ->amountBetween($filters['min_amount'] ?? null, $filters['max_amount'] ?? null);

        $payments = $this->paginate($query, $filters, 'transaction_time');

        return [
            'data' => $payments->getCollection()->map($this->presentConfirmation(...))->all(),
            'meta' => $this->meta($payments),
        ];
    }

    /**
     * Stored STK pushes, newest first by default.
     *
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function stkPayments(array $filters = []): array
    {
        $query = MpesaStkPayment::query()
            ->when(filled($filters['search'] ?? null), fn (Builder $query) => $query->search((string) $filters['search']))
            ->when(filled($filters['msisdn'] ?? null), fn (Builder $query) => $query->forMsisdn(Msisdn::normalize((string) $filters['msisdn'])))
            ->when(filled($filters['shortcode'] ?? null), fn (Builder $query) => $query->forShortcode($filters['shortcode']))
            ->when(filled($filters['transaction_id'] ?? null), fn (Builder $query) => $query->where('transaction_id', $filters['transaction_id']))
            ->when(filled($filters['merchant_request_id'] ?? null), fn (Builder $query) => $query->where('merchant_request_id', $filters['merchant_request_id']))
            ->when(filled($filters['checkout_request_id'] ?? null), fn (Builder $query) => $query->where('checkout_request_id', $filters['checkout_request_id']))
            ->when(($filters['status'] ?? null) === 'paid', fn (Builder $query) => $query->paid())
            ->when(($filters['status'] ?? null) === 'pending', fn (Builder $query) => $query->awaitingCallback())
            ->initiatedBetween($this->date($filters['from'] ?? null), $this->date($filters['to'] ?? null))
            ->amountBetween($filters['min_amount'] ?? null, $filters['max_amount'] ?? null);

        $payments = $this->paginate($query, $filters, 'created_at');

        return [
            'data' => $payments->getCollection()->map($this->presentStkPayment(...))->all(),
            'meta' => $this->meta($payments),
        ];
    }

    /**
     * Shape a C2B confirmation for the API.
     *
     * @return array<string, mixed>
     */
    protected function presentConfirmation(MpesaConfirmation $confirmation): array
    {
        return [
            'id' => $confirmation->id,
            'transaction_id' => $confirmation->transaction_id,
            'transaction_type' => $confirmation->transaction_type,
            'transaction_time' => $confirmation->transaction_time?->toIso8601String(),
            'amount' => $confirmation->transaction_amount,
            'business_shortcode' => $confirmation->business_shortcode,
            'bill_reference' => $confirmation->billref_no,
            'invoice_number' => $confirmation->invoice_no,
            'third_party_transaction_id' => $confirmation->thirdparty_transid,
            'payer' => [
                'msisdn' => $confirmation->mobile_number,
                'name' => $confirmation->customer_name,
            ],
            'recorded_at' => $confirmation->created_at?->toIso8601String(),
        ];
    }

    /**
     * Shape an STK push for the API.
     *
     * @return array<string, mixed>
     */
    protected function presentStkPayment(MpesaStkPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'merchant_request_id' => $payment->merchant_request_id,
            'checkout_request_id' => $payment->checkout_request_id,
            'transaction_id' => $payment->transaction_id,
            'transaction_date' => $payment->transaction_date?->toIso8601String(),
            'amount' => $payment->amount,
            'msisdn' => $payment->msisdn,
            'business_shortcode' => $payment->business_shortcode,
            'status' => $payment->is_paid ? 'paid' : 'pending',
            'initiated_at' => $payment->created_at?->toIso8601String(),
        ];
    }

    /**
     * Apply the requested sort and page size.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, TModel>
     */
    protected function paginate(Builder $query, array $filters, string $default): LengthAwarePaginator
    {
        return $query
            ->orderBy((string) ($filters['sort'] ?? $default), (string) ($filters['direction'] ?? 'desc'))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();
    }

    /**
     * The pagination metadata returned beside every list.
     *
     * @param  LengthAwarePaginator<int, Model>  $payments
     * @return array<string, mixed>
     */
    protected function meta(LengthAwarePaginator $payments): array
    {
        return [
            'current_page' => $payments->currentPage(),
            'per_page' => $payments->perPage(),
            'total' => $payments->total(),
            'last_page' => $payments->lastPage(),
            'from' => $payments->firstItem(),
            'to' => $payments->lastItem(),
            'next_page_url' => $payments->nextPageUrl(),
            'previous_page_url' => $payments->previousPageUrl(),
        ];
    }

    /**
     * Parse a date filter, ignoring anything blank.
     */
    protected function date(mixed $value): ?Carbon
    {
        return filled($value) ? Carbon::parse($value) : null;
    }
}
