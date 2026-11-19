<?php

namespace Tests\Feature\Mpesa;

use App\Jobs\ProcessC2bConfirmation;
use App\Models\MpesaCallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithMpesa;
use Tests\TestCase;

class C2bValidationTest extends TestCase
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
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'TransID' => 'TGH4K1LMNO',
            'TransAmount' => '1500.00',
            'BusinessShortCode' => '600000',
            'BillRefNumber' => 'INV-900',
            'MSISDN' => '254712345678',
        ], $overrides);
    }

    #[Test]
    public function it_accepts_a_payment_that_passes_every_check(): void
    {
        $this->fromMpesa()->postJson('/api/v1/callbacks/c2b/validation', $this->payload())
            ->assertOk()
            ->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

        // The decision is made inline, never queued: M-PESA is waiting on it.
        Queue::assertNothingPushed();
        $this->assertSame('processed', MpesaCallback::query()->sole()->status->value);
    }

    #[Test]
    public function it_rejects_a_payment_to_a_shortcode_that_is_not_ours(): void
    {
        $this->fromMpesa()->postJson('/api/v1/callbacks/c2b/validation', $this->payload(['BusinessShortCode' => '999999']))
            ->assertOk()
            ->assertExactJson(['ResultCode' => 'C2B00015', 'ResultDesc' => 'Invalid Shortcode']);
    }

    #[Test]
    public function it_rejects_an_amount_outside_the_configured_bounds(): void
    {
        config()->set(['mpesa.validation.min_amount' => 100, 'mpesa.validation.max_amount' => 1000]);

        $this->fromMpesa()->postJson('/api/v1/callbacks/c2b/validation', $this->payload(['TransAmount' => '50']))
            ->assertExactJson(['ResultCode' => 'C2B00013', 'ResultDesc' => 'Invalid Amount']);

        $this->fromMpesa()->postJson('/api/v1/callbacks/c2b/validation', $this->payload(['TransAmount' => '5000']))
            ->assertExactJson(['ResultCode' => 'C2B00013', 'ResultDesc' => 'Invalid Amount']);

        $this->fromMpesa()->postJson('/api/v1/callbacks/c2b/validation', $this->payload(['TransAmount' => '500']))
            ->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    #[Test]
    public function it_rejects_a_bill_reference_that_does_not_match_the_expected_pattern(): void
    {
        config()->set([
            'mpesa.validation.require_bill_reference' => true,
            'mpesa.validation.bill_reference_pattern' => '/^INV-\d+$/',
        ]);

        $this->fromMpesa()->postJson('/api/v1/callbacks/c2b/validation', $this->payload(['BillRefNumber' => 'random']))
            ->assertExactJson(['ResultCode' => 'C2B00012', 'ResultDesc' => 'Invalid Account Number']);

        $this->fromMpesa()->postJson('/api/v1/callbacks/c2b/validation', $this->payload(['BillRefNumber' => '']))
            ->assertExactJson(['ResultCode' => 'C2B00012', 'ResultDesc' => 'Invalid Account Number']);

        $this->fromMpesa()->postJson('/api/v1/callbacks/c2b/validation', $this->payload(['BillRefNumber' => 'INV-42']))
            ->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    #[Test]
    public function it_accepts_everything_when_validation_is_switched_off(): void
    {
        config()->set('mpesa.validation.enabled', false);

        $this->fromMpesa()->postJson('/api/v1/callbacks/c2b/validation', $this->payload(['BusinessShortCode' => '999999']))
            ->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    #[Test]
    public function validation_never_stores_a_payment(): void
    {
        $this->fromMpesa()->postJson('/api/v1/callbacks/c2b/validation', $this->payload())->assertOk();

        $this->assertDatabaseEmpty('mpesa_confirmations');
        Queue::assertNotPushed(ProcessC2bConfirmation::class);
    }
}
