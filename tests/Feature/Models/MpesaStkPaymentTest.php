<?php

namespace Tests\Feature\Models;

use App\Models\MpesaStkPayment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MpesaStkPaymentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_separates_paid_pushes_from_those_awaiting_a_callback(): void
    {
        $paid = MpesaStkPayment::factory()->paid()->create();
        $pending = MpesaStkPayment::factory()->create();

        $this->assertSame([$paid->id], MpesaStkPayment::query()->paid()->pluck('id')->all());
        $this->assertSame([$pending->id], MpesaStkPayment::query()->awaitingCallback()->pluck('id')->all());
        $this->assertTrue($paid->is_paid);
        $this->assertFalse($pending->is_paid);
    }

    #[Test]
    public function it_finds_a_push_by_its_pair_of_request_ids(): void
    {
        $payment = MpesaStkPayment::factory()->create([
            'merchant_request_id' => '29115-34620561-1',
            'checkout_request_id' => 'ws_CO_191220191020363925',
        ]);
        MpesaStkPayment::factory()->create();

        $found = MpesaStkPayment::query()
            ->forRequest('29115-34620561-1', 'ws_CO_191220191020363925')
            ->first();

        $this->assertTrue($payment->is($found));
    }

    #[Test]
    public function it_exposes_the_mpesa_transaction_date_as_a_date(): void
    {
        $payment = MpesaStkPayment::factory()->create(['transaction_date' => '20240820105742']);

        $this->assertInstanceOf(CarbonImmutable::class, $payment->fresh()->transaction_date);
        $this->assertSame('2024-08-20 10:57:42', $payment->fresh()->transaction_date->toDateTimeString());
        $this->assertNull(MpesaStkPayment::factory()->create()->fresh()->transaction_date);
    }

    #[Test]
    public function it_casts_the_amount_to_an_integer(): void
    {
        $payment = MpesaStkPayment::factory()->create(['amount' => '150']);

        $this->assertSame(150, $payment->fresh()->amount);
    }
}
