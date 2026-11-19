<?php

namespace Tests\Feature\Mpesa;

use App\Models\MpesaStkPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithMpesa;
use Tests\TestCase;

class StkPushTest extends TestCase
{
    use InteractsWithMpesa, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMpesa();
    }

    #[Test]
    public function it_pushes_a_payment_request_using_backend_configuration(): void
    {
        $this->fakeMpesa('stk_push', [
            'MerchantRequestID' => '29115-34620561-1',
            'CheckoutRequestID' => 'ws_CO_191220191020363925',
            'ResponseCode' => '0',
            'ResponseDescription' => 'Success. Request accepted for processing',
            'CustomerMessage' => 'Success. Request accepted for processing',
        ]);

        $response = $this->postJson('/api/v1/mpesa/stk/push', [
            'amount' => 150,
            'msisdn' => '0712345678',
        ]);

        $response->assertOk()->assertJsonPath('data.CheckoutRequestID', 'ws_CO_191220191020363925');

        Http::assertSent(function ($request) {
            return $request->url() === $this->endpointUrl('stk_push')
                && $request['BusinessShortCode'] === '174379'
                && $request['TransactionType'] === 'CustomerPayBillOnline'
                && $request['CallBackURL'] === 'https://example.test/api/v1/mpesa/stk/callback'
                && $request['PartyA'] === '254712345678'
                && $request['Amount'] === 150
                && $request['Password'] === base64_encode('174379test-passkey'.$request['Timestamp']);
        });

        $this->assertDatabaseHas('mpesa_stk_payments', [
            'merchant_request_id' => '29115-34620561-1',
            'checkout_request_id' => 'ws_CO_191220191020363925',
            'business_shortcode' => '174379',
            'msisdn' => '254712345678',
            'amount' => 150,
            'transaction_id' => null,
        ]);
    }

    #[Test]
    public function it_ignores_any_settings_a_caller_tries_to_send(): void
    {
        $this->fakeMpesa('stk_push', [
            'MerchantRequestID' => '29115-34620561-3',
            'CheckoutRequestID' => 'ws_CO_3',
            'ResponseCode' => '0',
        ]);

        $this->postJson('/api/v1/mpesa/stk/push', [
            'amount' => 150,
            'msisdn' => '0712345678',
            'consumer_key' => 'attacker-key',
            'shortcode' => '999999',
            'stk_callback' => 'https://attacker.test/callback',
        ])->assertOk();

        Http::assertSent(fn ($request) => $request->url() === $this->endpointUrl('stk_push')
            && $request['BusinessShortCode'] === '174379'
            && $request['CallBackURL'] === 'https://example.test/api/v1/mpesa/stk/callback');
    }

    #[Test]
    public function it_rejects_a_mobile_number_that_is_not_a_kenyan_msisdn(): void
    {
        $this->postJson('/api/v1/mpesa/stk/push', ['amount' => 10, 'msisdn' => '12345'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['msisdn']);
    }

    #[Test]
    public function it_rejects_a_request_without_an_amount_or_a_payer(): void
    {
        $this->postJson('/api/v1/mpesa/stk/push', [])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['amount', 'msisdn']);
    }

    #[Test]
    public function it_reports_a_push_mpesa_rejected_without_recording_it(): void
    {
        $this->fakeMpesa('stk_push', [
            'requestId' => '1234',
            'errorCode' => '400.002.02',
            'errorMessage' => 'Bad Request - Invalid Amount',
        ], status: 400);

        $this->postJson('/api/v1/mpesa/stk/push', ['amount' => 1, 'msisdn' => '254712345678'])
            ->assertStatus(502)
            ->assertJsonPath('error_code', '400.002.02');

        $this->assertSame(0, MpesaStkPayment::query()->count());
    }

    #[Test]
    public function it_does_not_record_a_push_mpesa_accepted_without_request_ids(): void
    {
        $this->fakeMpesa('stk_push', ['ResponseCode' => '0', 'ResponseDescription' => 'Success']);

        $this->postJson('/api/v1/mpesa/stk/push', ['amount' => 10, 'msisdn' => '254712345678'])
            ->assertOk();

        $this->assertSame(0, MpesaStkPayment::query()->count());
    }
}
