<?php

namespace Tests\Feature\Mpesa;

use App\Enums\MpesaCallbackStatus;
use App\Jobs\ForwardConfirmedPayment;
use App\Jobs\ProcessStkCallback;
use App\Models\MpesaCallback;
use App\Models\MpesaStkPayment;
use App\Services\MpesaStkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\InteractsWithMpesa;
use Tests\TestCase;

class StkCallbackTest extends TestCase
{
    use InteractsWithMpesa, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMpesa();
        Queue::fake();
    }

    /**
     * @return array<string, mixed>
     */
    protected function paidPayload(string $checkoutRequestId = 'ws_CO_191220191020363925'): array
    {
        return [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => '29115-34620561-1',
                    'CheckoutRequestID' => $checkoutRequestId,
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 150],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'SGR51YQ9AB'],
                            ['Name' => 'TransactionDate', 'Value' => 20260815160000],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                        ],
                    ],
                ],
            ],
        ];
    }

    #[Test]
    public function it_queues_the_callback_and_acknowledges_immediately(): void
    {
        MpesaStkPayment::factory()->create([
            'merchant_request_id' => '29115-34620561-1',
            'checkout_request_id' => 'ws_CO_191220191020363925',
        ]);

        $this->fromMpesa()->postJson('/api/v1/callbacks/stk', $this->paidPayload())
            ->assertOk()
            ->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

        $callback = MpesaCallback::query()->sole();
        $this->assertSame('ws_CO_191220191020363925', $callback->reference);
        Queue::assertPushed(ProcessStkCallback::class, fn ($job) => $job->callback->is($callback));
        Queue::assertPushedOn('mpesa', ProcessStkCallback::class);
    }

    #[Test]
    public function the_job_records_the_receipt_and_forwards_the_payment(): void
    {
        $payment = MpesaStkPayment::factory()->create([
            'merchant_request_id' => '29115-34620561-1',
            'checkout_request_id' => 'ws_CO_191220191020363925',
        ]);
        $this->fromMpesa()->postJson('/api/v1/callbacks/stk', $this->paidPayload())->assertOk();
        $callback = MpesaCallback::query()->sole();

        app(MpesaStkService::class)->recordPushOutcome($callback);

        $payment->refresh();
        $this->assertSame('SGR51YQ9AB', $payment->transaction_id);
        $this->assertSame(150, $payment->amount);
        $this->assertSame('254712345678', $payment->msisdn);
        $this->assertSame(0, $payment->result_code);
        $this->assertSame('paid', $payment->status);
        $this->assertSame('2026-08-15 16:00:00', $payment->transaction_date->toDateTimeString());
        $this->assertSame(MpesaCallbackStatus::Processed, $callback->fresh()->status);

        Queue::assertPushed(ForwardConfirmedPayment::class, fn ($job) => $job->source === 'stk'
            && $job->payment['transaction_id'] === 'SGR51YQ9AB');
    }

    #[Test]
    public function it_records_a_push_the_customer_cancelled_without_forwarding_it(): void
    {
        $payment = MpesaStkPayment::factory()->create([
            'merchant_request_id' => '29115-34620561-1',
            'checkout_request_id' => 'ws_CO_cancelled',
        ]);
        $callback = MpesaCallback::factory()->create([
            'payload' => [
                'Body' => [
                    'stkCallback' => [
                        'MerchantRequestID' => '29115-34620561-1',
                        'CheckoutRequestID' => 'ws_CO_cancelled',
                        'ResultCode' => 1032,
                        'ResultDesc' => 'Request cancelled by user',
                    ],
                ],
            ],
        ]);

        app(MpesaStkService::class)->recordPushOutcome($callback);

        $payment->refresh();
        $this->assertSame(1032, $payment->result_code);
        $this->assertSame('Request cancelled by user', $payment->result_desc);
        $this->assertNull($payment->transaction_id);
        $this->assertSame('failed', $payment->status);
        $this->assertNotNull($payment->completed_at);
        Queue::assertNotPushed(ForwardConfirmedPayment::class);
    }

    #[Test]
    public function it_retries_when_the_push_has_not_been_recorded_yet(): void
    {
        $callback = MpesaCallback::factory()->create(['payload' => $this->paidPayload('ws_CO_unknown')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No STK push recorded for ws_CO_unknown');

        app(MpesaStkService::class)->recordPushOutcome($callback);
    }

    #[Test]
    public function it_leaves_a_push_that_already_has_a_receipt_alone(): void
    {
        $payment = MpesaStkPayment::factory()->paid()->create([
            'merchant_request_id' => '29115-34620561-1',
            'checkout_request_id' => 'ws_CO_191220191020363925',
            'transaction_id' => 'ORIGINAL01',
        ]);
        $callback = MpesaCallback::factory()->create(['payload' => $this->paidPayload()]);

        app(MpesaStkService::class)->recordPushOutcome($callback);

        $this->assertSame('ORIGINAL01', $payment->fresh()->transaction_id);
        $this->assertSame(MpesaCallbackStatus::Ignored, $callback->fresh()->status);
        Queue::assertNotPushed(ForwardConfirmedPayment::class);
    }
}
