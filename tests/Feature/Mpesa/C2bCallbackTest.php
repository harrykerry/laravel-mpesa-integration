<?php

namespace Tests\Feature\Mpesa;

use App\Enums\MpesaCallbackStatus;
use App\Enums\MpesaCallbackType;
use App\Jobs\ForwardConfirmedPayment;
use App\Jobs\ProcessC2bConfirmation;
use App\Models\MpesaCallback;
use App\Models\MpesaConfirmation;
use App\Services\MpesaC2bService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithMpesa;
use Tests\TestCase;

class C2bCallbackTest extends TestCase
{
    use InteractsWithMpesa, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMpesa();
    }

    /**
     * @return array<string, mixed>
     */
    protected function confirmationPayload(array $overrides = []): array
    {
        return array_merge([
            'TransactionType' => 'Pay Bill',
            'TransID' => 'TGH4K1LMNO',
            'TransTime' => '20260815160000',
            'TransAmount' => '1500.00',
            'BusinessShortCode' => '600000',
            'BillRefNumber' => 'INV-900',
            'InvoiceNumber' => '',
            'OrgAccountBalance' => '45000.00',
            'ThirdPartyTransID' => '',
            'MSISDN' => '254712345678',
            'FirstName' => 'Asha',
            'MiddleName' => '',
            'LastName' => 'Kimani',
        ], $overrides);
    }

    #[Test]
    public function it_records_the_callback_queues_a_job_and_acknowledges_immediately(): void
    {
        Queue::fake();

        $this->fromMpesa()->postJson('/api/v1/callbacks/c2b/confirmation', $this->confirmationPayload())
            ->assertOk()
            ->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

        $callback = MpesaCallback::query()->sole();
        $this->assertSame(MpesaCallbackType::C2bConfirmation, $callback->type);
        $this->assertSame(MpesaCallbackStatus::Pending, $callback->status);
        $this->assertSame('TGH4K1LMNO', $callback->reference);
        $this->assertSame('Pay Bill', $callback->payload['TransactionType']);

        // Nothing is stored until the queued job runs.
        $this->assertSame(0, MpesaConfirmation::query()->count());
        Queue::assertPushed(ProcessC2bConfirmation::class, fn ($job) => $job->callback->is($callback));
        Queue::assertPushedOn('mpesa', ProcessC2bConfirmation::class);
    }

    #[Test]
    public function the_job_stores_the_payment_and_forwards_it(): void
    {
        Queue::fake();

        $this->fromMpesa()->postJson('/api/v1/callbacks/c2b/confirmation', $this->confirmationPayload())->assertOk();
        $callback = MpesaCallback::query()->sole();

        app(MpesaC2bService::class)->storeConfirmedPayment($callback);

        $this->assertDatabaseHas('mpesa_confirmations', [
            'transaction_id' => 'TGH4K1LMNO',
            'transaction_amount' => '1500.00',
            'mobile_number' => '254712345678',
            'billref_no' => 'INV-900',
            'business_shortcode' => '600000',
        ]);
        $this->assertSame(MpesaCallbackStatus::Processed, $callback->fresh()->status);
        $this->assertNotNull($callback->fresh()->processed_at);

        Queue::assertPushed(ForwardConfirmedPayment::class, fn ($job) => $job->source === 'c2b'
            && $job->payment['transaction_id'] === 'TGH4K1LMNO'
            && $job->payment['payer_name'] === 'Asha Kimani');
    }

    #[Test]
    public function a_repeated_delivery_is_not_queued_or_stored_twice(): void
    {
        Queue::fake();
        $payload = $this->confirmationPayload();

        $this->fromMpesa()->postJson('/api/v1/callbacks/c2b/confirmation', $payload)->assertOk();
        $this->fromMpesa()->postJson('/api/v1/callbacks/c2b/confirmation', $payload)->assertOk();

        $this->assertSame(1, MpesaCallback::query()->count());
        Queue::assertPushed(ProcessC2bConfirmation::class, 1);

        // Even run twice, the receipt is stored once.
        $callback = MpesaCallback::query()->sole();
        app(MpesaC2bService::class)->storeConfirmedPayment($callback);
        app(MpesaC2bService::class)->storeConfirmedPayment($callback);

        $this->assertSame(1, MpesaConfirmation::query()->count());
    }

    #[Test]
    public function a_payload_without_a_receipt_is_ignored_rather_than_retried(): void
    {
        Queue::fake();
        $callback = MpesaCallback::factory()->create(['payload' => ['MSISDN' => '254712345678']]);

        $this->assertNull(app(MpesaC2bService::class)->storeConfirmedPayment($callback));
        $this->assertSame(MpesaCallbackStatus::Ignored, $callback->fresh()->status);
        $this->assertSame(0, MpesaConfirmation::query()->count());
        Queue::assertNotPushed(ForwardConfirmedPayment::class);
    }
}
