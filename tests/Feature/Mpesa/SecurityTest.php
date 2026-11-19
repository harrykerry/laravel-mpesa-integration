<?php

namespace Tests\Feature\Mpesa;

use App\Models\MpesaCallback;
use App\Models\MpesaStkPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithMpesa;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use InteractsWithMpesa, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMpesa();
    }

    #[Test]
    public function it_refuses_a_callback_from_an_address_that_is_not_safaricoms(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/v1/callbacks/c2b/confirmation', ['TransID' => 'FAKE123'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'callback.ip_not_allowed');

        // Nothing from an unknown source is even recorded.
        $this->assertDatabaseEmpty('mpesa_callbacks');
    }

    #[Test]
    public function it_accepts_a_callback_from_a_safaricom_address(): void
    {
        $this->fromMpesa()
            ->postJson('/api/v1/callbacks/c2b/confirmation', ['TransID' => 'TGH4K1LMNO'])
            ->assertOk();

        $this->assertSame(1, MpesaCallback::query()->count());
    }

    #[Test]
    public function it_can_be_told_to_stop_enforcing_callback_addresses(): void
    {
        config()->set('mpesa.security.enforce_callback_ips', false);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/v1/callbacks/c2b/confirmation', ['TransID' => 'TGH4K1LMNO'])
            ->assertOk();
    }

    #[Test]
    public function it_refuses_a_callback_sent_to_a_host_that_is_not_ours(): void
    {
        config()->set('mpesa.security.allowed_hosts', ['pay.example.test']);

        $this->fromMpesa()
            ->postJson('http://attacker.test/api/v1/callbacks/c2b/confirmation', ['TransID' => 'X'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'host.not_allowed');

        $this->fromMpesa()
            ->postJson('http://pay.example.test/api/v1/callbacks/c2b/confirmation', ['TransID' => 'TGH4K1LMNO'])
            ->assertOk();
    }

    #[Test]
    public function it_allows_subdomains_when_the_allowlist_starts_with_a_dot(): void
    {
        config()->set('mpesa.security.allowed_hosts', ['.example.test']);

        $this->fromMpesa()
            ->postJson('http://pay.example.test/api/v1/callbacks/c2b/confirmation', ['TransID' => 'TGH4K1LMNO'])
            ->assertOk();
    }

    #[Test]
    public function it_requires_an_api_key_on_the_endpoints_we_own(): void
    {
        config()->set('mpesa.security.api_keys', ['frontend-key', 'ops-key']);

        $this->postJson('/api/v1/mpesa/stk/push', ['amount' => 10, 'msisdn' => '254712345678'])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'api_key.invalid');

        $this->withHeader('X-API-Key', 'wrong-key')
            ->getJson('/api/v1/payments/c2b')
            ->assertUnauthorized();

        $this->withHeader('X-API-Key', 'ops-key')
            ->getJson('/api/v1/payments/c2b')
            ->assertOk();

        $this->withToken('frontend-key')
            ->getJson('/api/v1/payments/stk')
            ->assertOk();
    }

    #[Test]
    public function callbacks_never_require_an_api_key(): void
    {
        config()->set('mpesa.security.api_keys', ['frontend-key']);

        $this->fromMpesa()
            ->postJson('/api/v1/callbacks/c2b/confirmation', ['TransID' => 'TGH4K1LMNO'])
            ->assertOk();
    }

    #[Test]
    public function a_repeated_push_with_the_same_idempotency_key_is_only_sent_once(): void
    {
        $this->fakeMpesa('stk_push', [
            'MerchantRequestID' => '29115-34620561-1',
            'CheckoutRequestID' => 'ws_CO_once',
            'ResponseCode' => '0',
            'CustomerMessage' => 'Request accepted',
        ]);
        $payload = ['amount' => 150, 'msisdn' => '0712345678'];

        $first = $this->withHeader('Idempotency-Key', 'checkout-9001')
            ->postJson('/api/v1/mpesa/stk/push', $payload)
            ->assertOk();

        $second = $this->withHeader('Idempotency-Key', 'checkout-9001')
            ->postJson('/api/v1/mpesa/stk/push', $payload)
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json(), $second->json());

        // One push reached M-PESA, and one row was written.
        Http::assertSentCount(2); // the token, then one push
        $this->assertSame(1, MpesaStkPayment::query()->count());
    }

    #[Test]
    public function a_different_idempotency_key_sends_another_push(): void
    {
        $this->fakeMpesa('stk_push', [
            'MerchantRequestID' => '29115-34620561-2',
            'CheckoutRequestID' => 'ws_CO_twice',
            'ResponseCode' => '0',
        ]);
        $payload = ['amount' => 150, 'msisdn' => '0712345678'];

        $this->withHeader('Idempotency-Key', 'checkout-1')->postJson('/api/v1/mpesa/stk/push', $payload)->assertOk();
        $this->withHeader('Idempotency-Key', 'checkout-2')->postJson('/api/v1/mpesa/stk/push', $payload)->assertOk();

        Http::assertSentCount(3); // the token, then two pushes
    }

    #[Test]
    public function a_repeated_push_with_no_key_at_all_is_caught_inside_the_auto_window(): void
    {
        config()->set('mpesa.security.idempotency.auto_window', 60);
        $this->fakeMpesa('stk_push', [
            'MerchantRequestID' => '29115-34620561-9',
            'CheckoutRequestID' => 'ws_CO_auto',
            'ResponseCode' => '0',
        ]);
        $payload = ['amount' => 150, 'msisdn' => '0712345678'];

        $this->postJson('/api/v1/mpesa/stk/push', $payload)->assertOk();
        $this->postJson('/api/v1/mpesa/stk/push', $payload)
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        Http::assertSentCount(2); // the token, then one push
        $this->assertSame(1, MpesaStkPayment::query()->count());

        // A different amount is a different request.
        $this->postJson('/api/v1/mpesa/stk/push', ['amount' => 200, 'msisdn' => '0712345678'])->assertOk();
        Http::assertSentCount(3);
    }

    #[Test]
    public function the_auto_window_can_be_switched_off(): void
    {
        config()->set('mpesa.security.idempotency.auto_window', 0);
        $this->fakeMpesa('stk_push', [
            'MerchantRequestID' => 'm',
            'CheckoutRequestID' => 'c',
            'ResponseCode' => '0',
        ]);
        $payload = ['amount' => 150, 'msisdn' => '0712345678'];

        $this->postJson('/api/v1/mpesa/stk/push', $payload)->assertOk();
        $this->postJson('/api/v1/mpesa/stk/push', $payload)->assertOk()->assertHeaderMissing('Idempotency-Replayed');

        Http::assertSentCount(3); // the token, then two pushes
    }

    #[Test]
    public function the_idempotency_key_can_be_made_compulsory(): void
    {
        config()->set('mpesa.security.idempotency.required', true);

        $this->postJson('/api/v1/mpesa/stk/push', ['amount' => 10, 'msisdn' => '254712345678'])
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'idempotency.key_missing');
    }

    #[Test]
    public function it_limits_how_often_one_payer_can_be_prompted(): void
    {
        config()->set(['mpesa.rate_limits.push_per_payer' => 2, 'mpesa.security.idempotency.auto_window' => 0]);
        $this->fakeMpesa('stk_push', ['MerchantRequestID' => 'm', 'CheckoutRequestID' => 'c', 'ResponseCode' => '0']);
        $payload = ['amount' => 10, 'msisdn' => '0712345678'];

        $this->postJson('/api/v1/mpesa/stk/push', $payload)->assertOk();
        $this->postJson('/api/v1/mpesa/stk/push', $payload)->assertOk();

        $this->postJson('/api/v1/mpesa/stk/push', $payload)
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'rate_limit.exceeded');

        // A different payer is unaffected.
        $this->postJson('/api/v1/mpesa/stk/push', ['amount' => 10, 'msisdn' => '0787654321'])->assertOk();
    }

    #[Test]
    public function it_refuses_to_call_a_host_that_is_not_allowlisted(): void
    {
        config()->set([
            'mpesa.base_urls.sandbox' => 'https://attacker.test',
            'mpesa.security.allowed_outbound_hosts' => ['sandbox.safaricom.co.ke'],
        ]);
        Http::fake();

        $this->postJson('/api/v1/mpesa/stk/push', ['amount' => 10, 'msisdn' => '254712345678'])
            ->assertStatus(500)
            ->assertJsonPath('error_code', 'host.not_allowed');

        Http::assertNothingSent();
    }
}
