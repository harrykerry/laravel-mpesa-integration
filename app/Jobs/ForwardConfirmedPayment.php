<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Forwards a confirmed payment to another system, on the queue.
 *
 * This is the outbound half of the callback flow: once a payment is stored,
 * whoever needs to know is told over HTTP, with retries, so a receiver being
 * down never costs us the payment. Each delivery is signed with an HMAC of
 * the body so the receiver can verify it came from here.
 */
class ForwardConfirmedPayment implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $source  Which M-PESA product the payment came from: c2b or stk.
     * @param  array<string, mixed>  $payment
     */
    public function __construct(
        public readonly string $source,
        public readonly array $payment,
    ) {
        $this->onConnection(config('mpesa.queue.connection'))
            ->onQueue((string) config('mpesa.queue.name'));

        $this->tries = (int) config('mpesa.forwarding.tries');
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
     * Deliver the payment to the configured URL, if there is one.
     */
    public function handle(): void
    {
        $url = (string) config('mpesa.forwarding.url');

        if (blank($url)) {
            return;
        }

        $body = json_encode([
            'source' => $this->source,
            'payment' => $this->payment,
        ], JSON_THROW_ON_ERROR);

        $response = Http::withBody($body, 'application/json')
            ->withHeaders([
                'X-Mpesa-Source' => $this->source,
                'X-Mpesa-Signature' => hash_hmac('sha256', $body, (string) config('mpesa.forwarding.secret')),
            ])
            ->timeout((int) config('mpesa.forwarding.timeout'))
            ->acceptJson()
            ->post($url);

        if ($response->failed()) {
            Log::channel('mpesa')->warning('FORWARD: delivery was rejected, will retry', [
                'source' => $this->source,
                'status' => $response->status(),
                'reference' => $this->payment['transaction_id'] ?? null,
            ]);

            $response->throw();
        }

        Log::channel('mpesa')->info('FORWARD: payment delivered', [
            'source' => $this->source,
            'reference' => $this->payment['transaction_id'] ?? null,
        ]);
    }

    /**
     * Record a payment that could not be delivered after every retry.
     */
    public function failed(?Throwable $e): void
    {
        Log::channel('mpesa')->critical('FORWARD_FAILED: payment was not delivered', [
            'source' => $this->source,
            'reference' => $this->payment['transaction_id'] ?? null,
            'error' => $e?->getMessage(),
        ]);
    }
}
