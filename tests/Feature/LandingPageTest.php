<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithMpesa;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use InteractsWithMpesa, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMpesa();
    }

    #[Test]
    public function the_landing_page_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Safaricom M-PESA for Laravel')
            ->assertSee('/api/v1/mpesa/stk/push')
            ->assertSee('haroldkerry@gmail.com');
    }

    #[Test]
    public function it_switches_between_endpoint_groups(): void
    {
        Livewire::test('welcome')
            ->assertSee('/api/v1/mpesa/stk/push')
            ->set('tab', 'payouts')
            ->assertSee('/api/v1/mpesa/payouts/customer')
            ->assertDontSee('/api/v1/mpesa/qr-code')
            ->set('tab', 'callbacks')
            ->assertSee('/api/v1/callbacks/c2b/validation');
    }

    #[Test]
    public function it_shows_which_settings_are_in_place_without_showing_them(): void
    {
        config()->set('mpesa.security.api_keys', ['mpk_secret_value']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Daraja app credentials')
            ->assertDontSee('mpk_secret_value')
            ->assertDontSee('test-secret');
    }

    #[Test]
    public function the_demo_push_is_off_unless_it_is_switched_on(): void
    {
        config()->set('mpesa.demo_enabled', false);
        Http::fake();

        Livewire::test('welcome')
            ->set('msisdn', '0712345678')
            ->call('sendDemoPush')
            ->assertSet('demoResult', null)
            ->assertSee('The demo is switched off');

        Http::assertNothingSent();
    }

    #[Test]
    public function the_demo_push_runs_the_same_service_the_api_uses(): void
    {
        config()->set('mpesa.demo_enabled', true);
        $this->fakeMpesa('stk_push', [
            'MerchantRequestID' => '29115-34620561-7',
            'CheckoutRequestID' => 'ws_CO_demo',
            'ResponseCode' => '0',
            'CustomerMessage' => 'Success. Request accepted for processing',
        ]);

        Livewire::test('welcome')
            ->set('amount', 25)
            ->set('msisdn', '0712345678')
            ->call('sendDemoPush')
            ->assertSet('demoError', null)
            ->assertSee('ws_CO_demo');

        $this->assertDatabaseHas('mpesa_stk_payments', [
            'checkout_request_id' => 'ws_CO_demo',
            'msisdn' => '254712345678',
            'amount' => 25,
        ]);
    }

    #[Test]
    public function the_demo_push_reports_what_mpesa_refused(): void
    {
        config()->set('mpesa.demo_enabled', true);
        $this->fakeMpesa('stk_push', [
            'errorCode' => '400.002.02',
            'errorMessage' => 'Bad Request - Invalid Amount',
        ], status: 400);

        Livewire::test('welcome')
            ->set('msisdn', '0712345678')
            ->call('sendDemoPush')
            ->assertSee('Invalid Amount');
    }
}
