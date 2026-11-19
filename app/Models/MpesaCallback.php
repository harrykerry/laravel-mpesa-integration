<?php

namespace App\Models;

use App\Enums\MpesaCallbackStatus;
use App\Enums\MpesaCallbackType;
use Database\Factories\MpesaCallbackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * One callback M-PESA sent us, exactly as it arrived.
 */
#[Fillable([
    'type',
    'fingerprint',
    'reference',
    'payload',
    'ip_address',
    'status',
    'received_at',
])]
class MpesaCallback extends Model
{
    /** @use HasFactory<MpesaCallbackFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MpesaCallbackType::class,
            'status' => MpesaCallbackStatus::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Mark the callback as handled.
     */
    public function markProcessed(): void
    {
        $this->forceFill([
            'status' => MpesaCallbackStatus::Processed,
            'processed_at' => now(),
            'error' => null,
        ])->save();
    }

    /**
     * Mark the callback as handled, but deliberately not acted on.
     */
    public function markIgnored(string $reason): void
    {
        $this->forceFill([
            'status' => MpesaCallbackStatus::Ignored,
            'processed_at' => now(),
            'error' => $reason,
        ])->save();
    }

    /**
     * Mark the callback as having failed processing.
     */
    public function markFailed(string|Throwable $error): void
    {
        $this->forceFill([
            'status' => MpesaCallbackStatus::Failed,
            'error' => $error instanceof Throwable ? $error->getMessage() : $error,
        ])->save();
    }

    /**
     * Record another processing attempt.
     */
    public function countAttempt(): void
    {
        $this->increment('attempts');
    }

    /**
     * Scope the query to callbacks of one kind.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function ofType(Builder $query, MpesaCallbackType $type): void
    {
        $query->where('type', $type);
    }

    /**
     * Scope the query to callbacks that have not been handled yet.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', MpesaCallbackStatus::Pending);
    }

    /**
     * Scope the query to callbacks whose processing failed.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function failed(Builder $query): void
    {
        $query->where('status', MpesaCallbackStatus::Failed);
    }
}
