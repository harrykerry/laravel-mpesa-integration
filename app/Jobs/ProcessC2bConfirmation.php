<?php

namespace App\Jobs;

use App\Models\MpesaCallback;
use App\Services\MpesaC2bService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stores a C2B payment M-PESA has confirmed.
 *
 * The endpoint has already acknowledged M-PESA, so this runs on the queue
 * and may be retried without Safaricom ever waiting on us.
 */
class ProcessC2bConfirmation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly MpesaCallback $callback)
    {
        $this->onConnection(config('mpesa.queue.connection'))
            ->onQueue((string) config('mpesa.queue.name'));

        $this->tries = (int) config('mpesa.queue.tries');
        $this->timeout = (int) config('mpesa.queue.timeout');
    }

    /**
     * Seconds to wait between attempts.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return config('mpesa.queue.backoff');
    }

    /**
     * One job per recorded callback, however often it is dispatched.
     */
    public function uniqueId(): string
    {
        return (string) $this->callback->id;
    }

    /**
     * Hand the payload to the service that stores it.
     */
    public function handle(MpesaC2bService $c2b): void
    {
        $this->callback->countAttempt();

        $c2b->storeConfirmedPayment($this->callback);
    }

    /**
     * Record a callback that could not be stored after every retry.
     */
    public function failed(?Throwable $e): void
    {
        $this->callback->markFailed($e?->getMessage() ?? 'Processing failed');

        Log::channel('mpesa')->critical('CALLBACK_FAILED: C2B confirmation not stored', [
            'callback_id' => $this->callback->id,
            'reference' => $this->callback->reference,
            'error' => $e?->getMessage(),
        ]);
    }
}
