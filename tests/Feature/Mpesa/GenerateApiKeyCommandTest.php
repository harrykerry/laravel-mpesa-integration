<?php

namespace Tests\Feature\Mpesa;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateApiKeyCommandTest extends TestCase
{
    #[Test]
    public function it_prints_an_env_line_holding_a_new_key(): void
    {
        config()->set('mpesa.security.api_keys', []);

        $this->artisan('mpesa:api-key')
            ->expectsOutputToContain('MPESA_API_KEYS=mpk_')
            ->assertSuccessful();
    }

    #[Test]
    public function it_appends_to_the_keys_already_configured(): void
    {
        config()->set('mpesa.security.api_keys', ['mpk_existing']);

        $this->artisan('mpesa:api-key --for=checkout-frontend')
            ->expectsOutputToContain('MPESA_API_KEYS=mpk_existing,mpk_')
            ->expectsOutputToContain('checkout-frontend')
            ->assertSuccessful();

        $this->artisan('mpesa:api-key --replace')
            ->doesntExpectOutputToContain('mpk_existing,')
            ->assertSuccessful();
    }
}
