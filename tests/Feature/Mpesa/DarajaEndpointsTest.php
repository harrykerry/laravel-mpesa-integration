<?php

namespace Tests\Feature\Mpesa;

use App\Enums\MpesaCallbackStatus;
use App\Jobs\ProcessMpesaResult;
use App\Models\MpesaCallback;
use App\Models\MpesaStkPayment;
use App\Services\MpesaResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithMpesa;
use Tests\TestCase;

class DarajaEndpointsTest extends TestCase
{
    use InteractsWithMpesa, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMpesa();
    }

    #[Test]
    public function it_pays_a_customer_with_b2c(): void
    {
        $this->fakeMpesa('b2c', [
            'ConversationID' => 'AG_20260815_0000abc',
            'OriginatorConversationID' => '16740-34861180-1',
            'ResponseCode' => '0',
            'ResponseDescription' => 'Accept the service request successfully.',
        ]);

        $this->postJson('/api/v1/mpesa/payouts/customer', ['amount' => 500, 'msisdn' => '0712345678'])
            ->assertOk()
            ->assertJsonPath('data.conversation_id', 'AG_20260815_0000abc')
            ->assertJsonPath('message', 'Accept the service request successfully.');

        Http::assertSent(fn ($request) => $request->url() === $this->endpointUrl('b2c')
            && $request['CommandID'] === 'BusinessPayment'
            && $request['PartyA'] === '600000'
            && $request['PartyB'] === '254712345678'
            && $request['Amount'] === 500
            && $request['InitiatorName'] === 'testapi'
            && $request['ResultURL'] === 'https://example.test/api/v1/mpesa/results/result');
    }

    #[Test]
    public function it_pays_another_business_with_b2b(): void
    {
        $this->fakeMpesa('b2b', ['ConversationID' => 'AG_b2b', 'ResponseCode' => '0']);

        $this->postJson('/api/v1/mpesa/payouts/business', [
            'amount' => 2500,
            'shortcode' => '888880',
            'account_reference' => 'SUPPLIER-12',
            'command_id' => 'BusinessBuyGoods',
        ])->assertOk()->assertJsonPath('data.conversation_id', 'AG_b2b');

        Http::assertSent(fn ($request) => $request->url() === $this->endpointUrl('b2b')
            && $request['CommandID'] === 'BusinessBuyGoods'
            && $request['PartyB'] === '888880'
            && $request['AccountReference'] === 'SUPPLIER-12'
            && $request['SenderIdentifierType'] === '4');
    }

    #[Test]
    public function it_remits_tax(): void
    {
        $this->fakeMpesa('tax_remittance', ['ConversationID' => 'AG_tax', 'ResponseCode' => '0']);

        $this->postJson('/api/v1/mpesa/payouts/tax', [
            'amount' => 10000,
            'payment_registration_number' => 'PRN123456',
        ])->assertOk();

        Http::assertSent(fn ($request) => $request->url() === $this->endpointUrl('tax_remittance')
            && $request['CommandID'] === 'PayTaxToKRA'
            && $request['PartyB'] === '572572'
            && $request['AccountReference'] === 'PRN123456');
    }

    #[Test]
    public function it_queries_a_transactions_status(): void
    {
        $this->fakeMpesa('transaction_status', ['ConversationID' => 'AG_status', 'ResponseCode' => '0']);

        $this->postJson('/api/v1/mpesa/transaction/status', ['transaction_id' => 'SGR51YQ9AB'])
            ->assertOk()
            ->assertJsonPath('data.conversation_id', 'AG_status');

        Http::assertSent(fn ($request) => $request->url() === $this->endpointUrl('transaction_status')
            && $request['CommandID'] === 'TransactionStatusQuery'
            && $request['TransactionID'] === 'SGR51YQ9AB');
    }

    #[Test]
    public function it_asks_for_the_account_balance(): void
    {
        $this->fakeMpesa('account_balance', ['ConversationID' => 'AG_balance', 'ResponseCode' => '0']);

        $this->postJson('/api/v1/mpesa/account-balance')->assertOk();

        Http::assertSent(fn ($request) => $request->url() === $this->endpointUrl('account_balance')
            && $request['CommandID'] === 'AccountBalance'
            && $request['PartyA'] === '600000');
    }

    #[Test]
    public function it_reverses_a_payment(): void
    {
        $this->fakeMpesa('reversal', ['ConversationID' => 'AG_reversal', 'ResponseCode' => '0']);

        $this->postJson('/api/v1/mpesa/transaction/reversal', [
            'transaction_id' => 'SGR51YQ9AB',
            'amount' => 150,
        ])->assertOk();

        Http::assertSent(fn ($request) => $request->url() === $this->endpointUrl('reversal')
            && $request['CommandID'] === 'TransactionReversal'
            && $request['TransactionID'] === 'SGR51YQ9AB'
            && $request['Amount'] === 150
            && $request['RecieverIdentifierType'] === '11');
    }

    #[Test]
    public function it_generates_a_qr_code(): void
    {
        // The QR API answers success as "00", not "0".
        $this->fakeMpesa('dynamic_qr', ['ResponseCode' => '00', 'QRCode' => 'base64-image-data']);

        $this->postJson('/api/v1/mpesa/qr-code', ['amount' => 200, 'reference' => 'INV-900'])
            ->assertOk()
            ->assertJsonPath('data.qr_code', 'base64-image-data')
            ->assertJsonPath('data.transaction_type', 'PB');

        Http::assertSent(fn ($request) => $request->url() === $this->endpointUrl('dynamic_qr')
            && $request['RefNo'] === 'INV-900'
            && $request['CPI'] === '600000');
    }

    #[Test]
    public function it_reports_a_push_mpesa_is_still_processing_as_pending(): void
    {
        $this->fakeMpesa('stk_query', [
            'requestId' => '1234',
            'errorCode' => '500.001.1001',
            'errorMessage' => 'The transaction is being processed',
        ], status: 500);

        $this->postJson('/api/v1/mpesa/stk/query', ['checkout_request_id' => 'ws_CO_1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
    }

    #[Test]
    public function it_writes_a_push_outcome_a_status_query_reveals(): void
    {
        $payment = MpesaStkPayment::factory()->create(['checkout_request_id' => 'ws_CO_1']);
        $this->fakeMpesa('stk_query', [
            'ResponseCode' => '0',
            'ResultCode' => '1032',
            'ResultDesc' => 'Request cancelled by user',
        ]);

        $this->postJson('/api/v1/mpesa/stk/query', ['checkout_request_id' => 'ws_CO_1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.result_code', 1032);

        $this->assertSame(1032, $payment->fresh()->result_code);
        $this->assertSame('Request cancelled by user', $payment->fresh()->result_desc);
    }

    #[Test]
    public function it_simulates_a_customer_payment_in_the_sandbox_only(): void
    {
        $this->fakeMpesa('c2b_simulate', ['ConversationID' => 'AG_sim', 'ResponseCode' => '0']);

        $this->postJson('/api/v1/mpesa/c2b/simulate', ['amount' => 100, 'msisdn' => '0712345678'])
            ->assertOk()
            ->assertJsonPath('data.conversation_id', 'AG_sim');

        config()->set('mpesa.environment', 'production');
        config()->set('mpesa.security.allowed_outbound_hosts', ['api.safaricom.co.ke']);

        $this->postJson('/api/v1/mpesa/c2b/simulate', ['amount' => 100, 'msisdn' => '0712345678'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'simulation.not_available');
    }

    #[Test]
    public function it_queues_a_result_callback_and_acknowledges_it(): void
    {
        Queue::fake();

        $this->fromMpesa()->postJson('/api/v1/callbacks/result', [
            'Result' => [
                'ResultType' => 0,
                'ResultCode' => 0,
                'ResultDesc' => 'The service request is processed successfully.',
                'ConversationID' => 'AG_20260815_0000abc',
                'TransactionID' => 'SGR51YQ9AB',
            ],
        ])->assertOk()->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

        $callback = MpesaCallback::query()->sole();
        $this->assertSame('AG_20260815_0000abc', $callback->reference);
        Queue::assertPushed(ProcessMpesaResult::class);
    }

    #[Test]
    public function the_result_job_reads_the_parameters_and_marks_the_callback(): void
    {
        $callback = MpesaCallback::factory()->create([
            'type' => 'result',
            'payload' => [
                'Result' => [
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'ConversationID' => 'AG_balance',
                    'ResultParameters' => [
                        'ResultParameter' => [
                            ['Key' => 'AccountBalance', 'Value' => 'Working Account|KES|46713.00'],
                            ['Key' => 'BOCompletedTime', 'Value' => '20260815160000'],
                        ],
                    ],
                ],
            ],
        ]);

        $results = app(MpesaResultService::class);
        $parameters = $results->parameters($callback->payload['Result']);
        $results->handle($callback);

        $this->assertSame('Working Account|KES|46713.00', $parameters['AccountBalance']);
        $this->assertSame(MpesaCallbackStatus::Processed, $callback->fresh()->status);
    }

    #[Test]
    public function a_queue_timeout_is_recorded_too(): void
    {
        Queue::fake();

        $this->fromMpesa()->postJson('/api/v1/callbacks/timeout', [
            'Result' => ['ConversationID' => 'AG_timeout', 'ResultCode' => 1, 'ResultDesc' => 'Timed out'],
        ])->assertOk();

        $this->assertSame('timeout', MpesaCallback::query()->sole()->type->value);
        Queue::assertPushed(ProcessMpesaResult::class);
    }
}
