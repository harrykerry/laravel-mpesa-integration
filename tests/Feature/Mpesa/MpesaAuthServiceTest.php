<?php

namespace Tests\Feature\Mpesa;

use App\Exceptions\MpesaException;
use App\Services\MpesaAuthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithMpesa;
use Tests\TestCase;

class MpesaAuthServiceTest extends TestCase
{
    use InteractsWithMpesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMpesa();
    }

    #[Test]
    public function it_requests_a_token_with_the_configured_credentials(): void
    {
        $this->fakeAccessToken('issued-token');

        $this->assertSame('issued-token', app(MpesaAuthService::class)->token());

        Http::assertSent(fn ($request) => str_contains($request->url(), '/oauth/v1/generate')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('test-key:test-secret')));
    }

    #[Test]
    public function it_reuses_the_cached_token_until_it_is_forgotten(): void
    {
        $this->fakeAccessToken('issued-token');
        $service = app(MpesaAuthService::class);

        $service->token();
        $service->token();

        Http::assertSentCount(1);

        $service->forgetToken();
        $service->token();

        Http::assertSentCount(2);
    }

    #[Test]
    public function it_caches_the_token_for_less_than_the_lifetime_mpesa_reports(): void
    {
        $this->fakeAccessToken('issued-token', expiresIn: 3600);
        config()->set('mpesa.token.leeway', 60);

        app(MpesaAuthService::class)->token();

        $this->assertSame('issued-token', Cache::get((string) config('mpesa.token.key')));
    }

    #[Test]
    public function it_fails_when_credentials_are_not_configured(): void
    {
        config()->set('mpesa.credentials.consumer_key', null);

        $this->expectException(MpesaException::class);
        $this->expectExceptionMessage('mpesa.credentials');

        app(MpesaAuthService::class)->token();
    }

    #[Test]
    public function it_fails_when_mpesa_rejects_the_credentials(): void
    {
        Http::fake([$this->tokenUrl() => Http::response(['errorMessage' => 'Invalid credentials'], 401)]);

        $this->expectException(MpesaException::class);
        $this->expectExceptionMessage('HTTP 401');

        app(MpesaAuthService::class)->token();
    }
}
