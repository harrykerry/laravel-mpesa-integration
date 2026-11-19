<?php

namespace App\Models;

use App\Enums\MpesaRequestStatus;
use App\Enums\MpesaRequestType;
use Database\Factories\MpesaRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An asynchronous request we made to M-PESA, and the outcome it reported.
 */
#[Fillable([
    'type',
    'conversation_id',
    'originator_conversation_id',
    'reference',
    'amount',
    'status',
    'request',
])]
class MpesaRequest extends Model
{
    /** @use HasFactory<MpesaRequestFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MpesaRequestType::class,
            'status' => MpesaRequestStatus::class,
            'amount' => 'decimal:2',
            'request' => 'array',
            'result' => 'array',
            'result_code' => 'integer',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Record the outcome M-PESA reported for this request.
     *
     * @param  array<string, mixed>  $result
     */
    public function recordOutcome(?int $resultCode, ?string $resultDesc, array $result, bool $timedOut = false): void
    {
        $this->forceFill([
            'status' => match (true) {
                $timedOut => MpesaRequestStatus::TimedOut,
                $resultCode === 0 => MpesaRequestStatus::Succeeded,
                default => MpesaRequestStatus::Failed,
            },
            'result_code' => $resultCode,
            'result_desc' => $resultDesc,
            'result' => $result,
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Scope the query to requests of one kind.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function ofType(Builder $query, MpesaRequestType $type): void
    {
        $query->where('type', $type);
    }

    /**
     * Scope the query to requests M-PESA has not reported on yet.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function awaitingResult(Builder $query): void
    {
        $query->where('status', MpesaRequestStatus::Accepted);
    }

    /**
     * Scope the query to the request a result callback belongs to.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function forConversation(Builder $query, ?string $conversationId, ?string $originatorConversationId = null): void
    {
        $query->where(function (Builder $query) use ($conversationId, $originatorConversationId): void {
            $query->when($conversationId, fn (Builder $query) => $query->orWhere('conversation_id', $conversationId))
                ->when($originatorConversationId, fn (Builder $query) => $query->orWhere('originator_conversation_id', $originatorConversationId));
        });
    }
}
