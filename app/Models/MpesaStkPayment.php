<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MpesaStkPaymentFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An STK (Lipa na M-PESA Online) push we initiated, and its outcome.
 */
#[Fillable([
    'merchant_request_id',
    'checkout_request_id',
    'transaction_id',
    'transaction_date',
    'amount',
    'msisdn',
    'business_shortcode',
])]
class MpesaStkPayment extends Model
{
    /** @use HasFactory<MpesaStkPaymentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    /**
     * M-PESA sends TransactionDate as a "YmdHis" string, so expose it as a date.
     */
    protected function transactionDate(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => filled($value)
                ? CarbonImmutable::createFromFormat('YmdHis', $value)
                : null,
            set: fn (CarbonImmutable|\DateTimeInterface|string|int|null $value) => match (true) {
                $value instanceof DateTimeInterface => $value->format('YmdHis'),
                default => $value === null ? null : (string) $value,
            },
        )->shouldCache();
    }

    /**
     * Whether M-PESA has confirmed the payment with a receipt.
     */
    protected function isPaid(): Attribute
    {
        return Attribute::get(fn (): bool => filled($this->transaction_id));
    }

    /**
     * Scope the query to pushes M-PESA has confirmed with a receipt.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function paid(Builder $query): void
    {
        $query->whereNotNull('transaction_id');
    }

    /**
     * Scope the query to pushes that are still awaiting a callback.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function awaitingCallback(Builder $query): void
    {
        $query->whereNull('transaction_id');
    }

    /**
     * Scope the query to a single payer.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function forMsisdn(Builder $query, string $msisdn): void
    {
        $query->where('msisdn', $msisdn);
    }

    /**
     * Scope the query to a single business shortcode.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function forShortcode(Builder $query, int|string $shortcode): void
    {
        $query->where('business_shortcode', $shortcode);
    }

    /**
     * Scope the query to the push M-PESA is reporting on.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function forRequest(Builder $query, string $merchantRequestId, string $checkoutRequestId): void
    {
        $query->where('merchant_request_id', $merchantRequestId)
            ->where('checkout_request_id', $checkoutRequestId);
    }

    /**
     * Scope the query to a free-text match across the identifying columns.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function search(Builder $query, string $term): void
    {
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($term)).'%';

        $query->where(function (Builder $query) use ($like): void {
            $query->where('transaction_id', 'like', $like)
                ->orWhere('merchant_request_id', 'like', $like)
                ->orWhere('checkout_request_id', 'like', $like)
                ->orWhere('msisdn', 'like', $like);
        });
    }

    /**
     * Scope the query to pushes initiated in a window.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function initiatedBetween(Builder $query, ?DateTimeInterface $from = null, ?DateTimeInterface $to = null): void
    {
        $query->when($from, fn (Builder $query) => $query->where('created_at', '>=', $from))
            ->when($to, fn (Builder $query) => $query->where('created_at', '<=', $to));
    }

    /**
     * Scope the query to an amount range.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function amountBetween(Builder $query, float|int|null $min = null, float|int|null $max = null): void
    {
        $query->when($min !== null, fn (Builder $query) => $query->where('amount', '>=', $min))
            ->when($max !== null, fn (Builder $query) => $query->where('amount', '<=', $max));
    }

    /**
     * Scope the query to the most recent pushes first.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function latestFirst(Builder $query): void
    {
        $query->orderByDesc('id');
    }
}
