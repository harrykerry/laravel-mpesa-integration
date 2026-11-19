<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MpesaConfirmationFactory;
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
                $value instanceof \DateTimeInterface => $value->format('YmdHis'),
                default => $value,
            },
        )->shouldCache();
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
