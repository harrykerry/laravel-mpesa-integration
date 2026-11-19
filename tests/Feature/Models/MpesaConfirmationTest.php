<?php

namespace Tests\Feature\Models;

use App\Models\MpesaConfirmation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MpesaConfirmationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_exposes_the_mpesa_transaction_time_as_a_date(): void
    {
        $confirmation = MpesaConfirmation::factory()->create([
            'transaction_time' => '20240816165723',
        ]);

        $this->assertInstanceOf(CarbonImmutable::class, $confirmation->fresh()->transaction_time);
        $this->assertSame('2024-08-16 16:57:23', $confirmation->fresh()->transaction_time->toDateTimeString());
    }

    #[Test]
    public function it_stores_a_date_instance_in_the_format_mpesa_uses(): void
    {
        $confirmation = MpesaConfirmation::factory()->create([
            'transaction_time' => CarbonImmutable::parse('2024-08-16 16:57:23'),
        ]);

        $this->assertDatabaseHas('mpesa_confirmations', [
            'id' => $confirmation->id,
            'transaction_time' => '20240816165723',
        ]);
    }

    #[Test]
    public function it_builds_the_customer_name_and_skips_missing_parts(): void
    {
        $confirmation = MpesaConfirmation::factory()->make([
            'first_name' => 'Asha',
            'middle_name' => 'NA',
            'last_name' => 'Kimani',
        ]);

        $this->assertSame('Asha Kimani', $confirmation->customer_name);
        $this->assertArrayHasKey('customer_name', $confirmation->toArray());
    }

    #[Test]
    public function it_scopes_confirmations_by_shortcode_and_msisdn(): void
    {
        $mine = MpesaConfirmation::factory()->create([
            'business_shortcode' => '600111',
            'mobile_number' => '254712345678',
        ]);
        MpesaConfirmation::factory()->create(['business_shortcode' => '600222']);

        $this->assertSame(
            [$mine->id],
            MpesaConfirmation::query()->forShortcode('600111')->pluck('id')->all()
        );
        $this->assertSame(
            [$mine->id],
            MpesaConfirmation::query()->forMsisdn('254712345678')->pluck('id')->all()
        );
    }
}
