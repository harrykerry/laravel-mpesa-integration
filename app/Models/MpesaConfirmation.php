<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MpesaConfirmationFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A C2B confirmation pushed to us by M-PESA for a completed payment.
 */
#[Fillable([
    'transaction_type',
    'transaction_id',
    'transaction_time',
    'transaction_amount',
    'business_shortcode',
    'billref_no',
    'invoice_no',
    'org_balance',
    'thirdparty_transid',
    'mobile_number',
    'first_name',
    'middle_name',
    'last_name',
])]
#[Appends('customer_name')]
class MpesaConfirmation extends Model
{
    /** @use HasFactory<MpesaConfirmationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transaction_amount' => 'decimal:2',
            'org_balance' => 'decimal:2',
        ];
    }

    /**
     * M-PESA sends TransTime as a "YmdHis" string, so expose it as a date.
     */
    protected function transactionTime(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => filled($value)
                ? CarbonImmutable::createFromFormat('YmdHis', $value)
                : null,
            set: fn (CarbonImmutable|\DateTimeInterface|string|null $value) => match (true) {
                $value instanceof DateTimeInterface => $value->format('YmdHis'),
                default => $value,
            },
        );
    }

    /**
     * The payer's name as M-PESA reported it.
     */
    protected function customerName(): Attribute
    {
        return Attribute::get(fn (): string => collect([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ])->reject(fn (?string $part) => blank($part) || $part === 'NA')
            ->implode(' '))->shouldCache();
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
     * Scope the query to a single payer.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function forMsisdn(Builder $query, string $msisdn): void
    {
        $query->where('mobile_number', $msisdn);
    }

    /**
     * Scope the query to a bill reference (the account the payer typed).
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function forBillReference(Builder $query, string $reference): void
    {
        $query->where('billref_no', $reference);
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
                ->orWhere('billref_no', 'like', $like)
                ->orWhere('invoice_no', 'like', $like)
                ->orWhere('thirdparty_transid', 'like', $like)
                ->orWhere('mobile_number', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('middle_name', 'like', $like)
                ->orWhere('last_name', 'like', $like);
        });
    }

    /**
     * Scope the query to transactions M-PESA timestamped in a window.
     *
     * The column holds M-PESA's "YmdHis" strings, which sort chronologically.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function transactedBetween(Builder $query, ?DateTimeInterface $from = null, ?DateTimeInterface $to = null): void
    {
        $query->when($from, fn (Builder $query) => $query->where('transaction_time', '>=', $from->format('YmdHis')))
            ->when($to, fn (Builder $query) => $query->where('transaction_time', '<=', $to->format('YmdHis')));
    }

    /**
     * Scope the query to an amount range.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function amountBetween(Builder $query, float|int|null $min = null, float|int|null $max = null): void
    {
        $query->when($min !== null, fn (Builder $query) => $query->where('transaction_amount', '>=', $min))
            ->when($max !== null, fn (Builder $query) => $query->where('transaction_amount', '<=', $max));
    }

    /**
     * Scope the query to the most recent transactions first.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function latestFirst(Builder $query): void
    {
        $query->orderByDesc('id');
    }
}
