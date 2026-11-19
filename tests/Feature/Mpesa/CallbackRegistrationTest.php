<?php

namespace Tests\Feature\Mpesa;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithMpesa;
use Tests\TestCase;

class CallbackRegistrationTest extends TestCase
{
    use InteractsWithMpesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMpesa();
    }

    #[Test]
    public function it_registers_the_configured_urls_with_mpesa(): void
    {
        $this->fakeMpesa('register_url', [
            'OriginatorCoversationID' => '7619-37765134-1',
            'ResponseCode' => '0',
            'ResponseDescription' => 'Success',
        ]);

        $this->postJson('/api/v1/mpesa/c2b/register-urls')
            ->assertOk()
            ->assertJsonPath('data.ResponseDescription', 'Success');

        Http::assertSent(fn ($request) => $request->url() === $this->endpointUrl('register_url')
            && $request['ShortCode'] === '600000'
            && $request['ResponseType'] === 'Completed'
            && $request['ConfirmationURL'] === 'https://example.test/api/v1/mpesa/c2b/confirmation'
            && $request['ValidationURL'] === 'https://example.test/api/v1/mpesa/c2b/validation');
    }

    #[Test]
    public function it_allows_overriding_the_shortcode_and_urls(): void
    {
        $this->fakeMpesa('register_url', ['ResponseCode' => '0', 'ResponseDescription' => 'Success']);

        $this->postJson('/api/v1/mpesa/c2b/register-urls', [
            'shortcode' => '600111',
            'confirmation_url' => 'https://example.test/other/confirmation',
            'validation_url' => 'https://example.test/other/validation',
            'response_type' => 'Cancelled',
        ])->assertOk();

        Http::assertSent(fn ($request) => $request->url() === $this->endpointUrl('register_url')
            && $request['ShortCode'] === '600111'
            && $request['ResponseType'] === 'Cancelled'
            && $request['ConfirmationURL'] === 'https://example.test/other/confirmation');
    }

    #[Test]
    public function it_refuses_a_plain_http_callback_url(): void
    {
        $this->postJson('/api/v1/mpesa/c2b/register-urls', [
            'confirmation_url' => 'http://example.test/insecure',
        ])->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['confirmation_url']);
    }
}
