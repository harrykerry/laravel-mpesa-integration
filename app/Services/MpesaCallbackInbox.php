<?php

namespace App\Services;

use App\Enums\MpesaCallbackType;
use App\Models\MpesaCallback;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes down every callback M-PESA sends before anything is done with it.
 *
 * The endpoint records the payload, acknowledges M-PESA and returns; a
 * queued job does the work. M-PESA resends a callback until it is
 * acknowledged, so an identical payload lands on the row already stored
 * instead of being processed a second time.
 */
class MpesaCallbackInbox
{
    /**
     * Record a received callback, or return the one already stored.
     *
     * Check wasRecentlyCreated on the result to tell a first delivery from
     * a repeat: only a first delivery should be queued.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordIncomingCallback(MpesaCallbackType $type, array $payload, ?string $ipAddress = null): MpesaCallback
    {
        DB::beginTransaction();

        try {
            $callback = MpesaCallback::query()->firstOrCreate(
                ['fingerprint' => $this->fingerprint($type, $payload)],
                [
                    'type' => $type,
                    'reference' => $this->reference($type, $payload),
                    'payload' => $payload,
                    'ip_address' => $ipAddress,
                    'received_at' => now(),
                ],
            );

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $context = [
            'callback_id' => $callback->id,
            'type' => $type->value,
            'reference' => $callback->reference,
            'ip' => $ipAddress,
        ];

        if ($callback->wasRecentlyCreated) {
            Log::channel('mpesa')->info('CALLBACK_RECEIVED', $context);
        } else {
            Log::channel('mpesa')->warning('CALLBACK_REPEATED: already recorded, not queued again', $context + [
                'status' => $callback->status->value,
            ]);
        }

        return $callback;
    }

    /**
     * Fingerprint a payload so repeat deliveries collide on one row.
     *
     * @param  array<string, mixed>  $payload
     */
    public function fingerprint(MpesaCallbackType $type, array $payload): string
    {
        return hash('sha256', $type->value.'|'.json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Pull the transaction identifier out of the payload, when it has one.
     *
     * @param  array<string, mixed>  $payload
     */
    public function reference(MpesaCallbackType $type, array $payload): ?string
    {
        $key = $type->referenceKey();

        if ($key === null) {
            return null;
        }

        $reference = Arr::get($payload, $key);

        return is_scalar($reference) ? (string) $reference : null;
    }

    /**
     * The M-PESA log channel.
     */
    public function log(): LoggerInterface
    {
        return Log::channel('mpesa');
    }
}
