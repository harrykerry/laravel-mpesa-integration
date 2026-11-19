<?php

namespace App\Jobs;

use App\Models\MpesaCallback;
use App\Services\MpesaStkService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records the outcome M-PESA reports for an STK push.
 *
 * The endpoint has already acknowledged M-PESA, so this runs on the queue
 * and may be retried without Safaricom ever waiting on us.
 */
class ProcessStkCallback implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * How many times the job may be attempted.
     */
    public int $tries = 5;

    /**
     * Seconds the job may run before it is killed.
     */
    public int $timeout = 30;

    /**
     * Seconds to wait between attempts.
     *
     * @var list<int>
     */
    public array $backoff = [10, 30, 60, 300, 900];

    public function __construct(public readonly MpesaCallback $callback)
    {
        $this->onConnection(config('mpesa.queue.connection'))
            ->onQueue((string) config('mpesa.queue.name'));
    }

    /**
     * One job per recorded callback, however often it is dispatched.
     */
    public function uniqueId(): string
    {
        return (string) $this->callback->id;
    }

    /**
     * Hand the payload to the service that records the outcome.
     */
    public function handle(MpesaStkService $stk): void
    {
        $this->callback->countAttempt();

        $stk->recordPushOutcome($this->callback);
    }

    /**
     * Record a callback that could not be processed after every retry.
     */
    public function failed(?Throwable $e): void
    {
        $this->callback->markFailed($e?->getMessage() ?? 'Processing failed');

        Log::channel('mpesa')->critical('CALLBACK_FAILED: STK callback not recorded', [
            'callback_id' => $this->callback->id,
            'reference' => $this->callback->reference,
            'error' => $e?->getMessage(),
        ]);
    }
}
