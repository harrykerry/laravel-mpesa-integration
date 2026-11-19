<?php

namespace Tests\Feature\Mpesa;

use App\Exceptions\MpesaException;
use App\Services\MpesaClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithMpesa;
use Tests\TestCase;

class MpesaClientTest extends TestCase
{
    use InteractsWithMpesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMpesa();
    }

    #[Test]
    public function it_posts_to_the_configured_endpoint_with_a_bearer_token(): void
    {
        $this->fakeMpesa('stk_push', ['ResponseCode' => '0', 'CheckoutRequestID' => 'ws_CO_1']);

        $body = app(MpesaClient::class)->postToMpesa('stk_push', ['Amount' => 10]);

        $this->assertSame('ws_CO_1', $body['CheckoutRequestID']);
        Http::assertSent(fn ($request) => $request->url() === $this->endpointUrl('stk_push')
            && $request->hasHeader('Authorization', 'Bearer test-access-token')
            && $request['Amount'] === 10);
    }

    #[Test]
    public function it_fails_when_mpesa_answers_with_a_non_zero_response_code(): void
    {
        $this->fakeMpesa('stk_push', [
            'ResponseCode' => '1',
            'ResponseDescription' => 'Insufficient funds',
        ]);

        $this->expectException(MpesaException::class);
        $this->expectExceptionMessage('Insufficient funds');

        app(MpesaClient::class)->postToMpesa('stk_push', []);
    }

    #[Test]
    public function it_fails_with_the_daraja_error_code_on_a_rejected_request(): void
    {
        $this->fakeMpesa('stk_push', [
            'requestId' => '1234',
            'errorCode' => '400.002.02',
            'errorMessage' => 'Bad Request - Invalid Amount',
        ], status: 400);

        try {
            app(MpesaClient::class)->postToMpesa('stk_push', []);
            $this->fail('Expected a MpesaException.');
        } catch (MpesaException $e) {
            $this->assertSame('400.002.02', $e->errorCode);
            $this->assertStringContainsString('Invalid Amount', $e->getMessage());
        }
    }

    #[Test]
    public function it_retries_a_server_side_failure_then_gives_up(): void
    {
        config()->set(['mpesa.http.retries' => 3, 'mpesa.http.retry_delay' => 0]);

        Http::fake([
            $this->tokenUrl() => Http::response(['access_token' => 'test-access-token', 'expires_in' => '3599']),
            $this->endpointUrl('stk_push') => Http::sequence()
                ->push(['errorMessage' => 'unavailable'], 503)
                ->push(['errorMessage' => 'unavailable'], 503)
                ->push(['ResponseCode' => '0', 'CheckoutRequestID' => 'ws_CO_2']),
        ]);

        $body = app(MpesaClient::class)->postToMpesa('stk_push', []);

        $this->assertSame('ws_CO_2', $body['CheckoutRequestID']);
    }

    #[Test]
    public function it_does_not_retry_a_request_mpesa_rejected(): void
    {
        config()->set(['mpesa.http.retries' => 3, 'mpesa.http.retry_delay' => 0]);
        $this->fakeMpesa('stk_push', ['errorCode' => '400.002.02', 'errorMessage' => 'Bad Request'], status: 400);

        try {
            app(MpesaClient::class)->postToMpesa('stk_push', []);
        } catch (MpesaException) {
            // expected
        }

        Http::assertSentCount(2); // the token, then one rejected request
    }

    #[Test]
    public function it_fails_when_an_endpoint_is_not_configured(): void
    {
        config()->set('mpesa.endpoints.stk_push', null);
        $this->fakeAccessToken();

        $this->expectException(MpesaException::class);
        $this->expectExceptionMessage('mpesa.endpoints.stk_push');

        app(MpesaClient::class)->postToMpesa('stk_push', []);
    }

    #[Test]
    public function it_keeps_secrets_out_of_the_logs(): void
    {
        $path = storage_path('logs/testing-mpesa.log');
        @unlink($path);
        config()->set('logging.channels.mpesa', [
            'driver' => 'single',
            'path' => $path,
            'level' => 'debug',
        ]);
        $this->fakeMpesa('stk_push', ['ResponseCode' => '0']);

        app(MpesaClient::class)->postToMpesa('stk_push', ['Password' => 'super-secret', 'Amount' => 1]);

        $written = (string) file_get_contents($path);
        @unlink($path);

        $this->assertStringNotContainsString('super-secret', $written);
        $this->assertStringContainsString('[redacted]', $written);
        $this->assertStringContainsString('MPESA_REQUEST: stk_push', $written);
        $this->assertStringContainsString('MPESA_RESPONSE: stk_push', $written);
    }
}
