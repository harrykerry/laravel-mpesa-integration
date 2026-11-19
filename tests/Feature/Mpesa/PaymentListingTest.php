<?php

namespace Tests\Feature\Mpesa;

use App\Models\MpesaConfirmation;
use App\Models\MpesaStkPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentListingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_lists_c2b_payments_in_a_paginated_envelope(): void
    {
        MpesaConfirmation::factory()->count(3)->create(['business_shortcode' => '600000']);

        $this->getJson('/api/v1/payments/c2b?per_page=2')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [['id', 'transaction_id', 'amount', 'transaction_time', 'payer' => ['msisdn', 'name']]],
                'meta' => ['current_page', 'per_page', 'total', 'last_page', 'next_page_url'],
            ]);
    }

    #[Test]
    public function it_returns_an_empty_page_rather_than_an_error(): void
    {
        $this->getJson('/api/v1/payments/c2b?shortcode=999999')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function it_filters_c2b_payments_by_shortcode_msisdn_and_bill_reference(): void
    {
        $wanted = MpesaConfirmation::factory()->create([
            'business_shortcode' => '600111',
            'mobile_number' => '254712345678',
            'billref_no' => 'INV-900',
        ]);
        MpesaConfirmation::factory()->count(2)->create(['business_shortcode' => '600222']);

        $this->getJson('/api/v1/payments/c2b?shortcode=600111')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wanted->id);

        $this->getJson('/api/v1/payments/c2b?msisdn=0712345678')
            ->assertOk()
            ->assertJsonPath('data.0.id', $wanted->id);

        $this->getJson('/api/v1/payments/c2b?bill_reference=INV-900')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function it_searches_c2b_payments_across_reference_name_and_receipt(): void
    {
        $wanted = MpesaConfirmation::factory()->create([
            'transaction_id' => 'TGH4K1LMNO',
            'last_name' => 'Wanjiru',
            'billref_no' => 'ORDER-55',
        ]);
        MpesaConfirmation::factory()->create(['transaction_id' => 'ZZZ1111111', 'last_name' => 'Otieno', 'billref_no' => 'X']);

        foreach (['TGH4K1', 'Wanjiru', 'ORDER-55'] as $term) {
            $this->getJson('/api/v1/payments/c2b?search='.urlencode($term))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $wanted->id);
        }
    }

    #[Test]
    public function it_filters_c2b_payments_by_date_window_and_amount(): void
    {
        $old = MpesaConfirmation::factory()->create([
            'transaction_time' => '20240101120000',
            'transaction_amount' => 50,
        ]);
        $recent = MpesaConfirmation::factory()->create([
            'transaction_time' => '20240815120000',
            'transaction_amount' => 5000,
        ]);

        $this->getJson('/api/v1/payments/c2b?from=2024-08-01&to=2024-08-31')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recent->id);

        $this->getJson('/api/v1/payments/c2b?max_amount=100')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $old->id);

        $this->getJson('/api/v1/payments/c2b?min_amount=1000&max_amount=10000')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recent->id);
    }

    #[Test]
    public function it_sorts_c2b_payments_on_request(): void
    {
        $small = MpesaConfirmation::factory()->create(['transaction_amount' => 10]);
        $large = MpesaConfirmation::factory()->create(['transaction_amount' => 9000]);

        $this->getJson('/api/v1/payments/c2b?sort=transaction_amount&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.0.id', $small->id)
            ->assertJsonPath('data.1.id', $large->id);

        $this->getJson('/api/v1/payments/c2b?sort=transaction_amount&direction=desc')
            ->assertOk()
            ->assertJsonPath('data.0.id', $large->id);
    }

    #[Test]
    public function it_filters_stk_pushes_by_status_and_identifiers(): void
    {
        $paid = MpesaStkPayment::factory()->paid()->create([
            'msisdn' => '254712345678',
            'checkout_request_id' => 'ws_CO_abc123',
        ]);
        $pending = MpesaStkPayment::factory()->create();

        $this->getJson('/api/v1/payments/stk?status=paid')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $paid->id)
            ->assertJsonPath('data.0.status', 'paid');

        $this->getJson('/api/v1/payments/stk?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.status', 'pending');

        $this->getJson('/api/v1/payments/stk?checkout_request_id=ws_CO_abc123')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/payments/stk?search=712345678')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $paid->id);
    }

    #[Test]
    public function it_rejects_filters_it_does_not_understand(): void
    {
        $this->getJson('/api/v1/payments/c2b?sort=secret_column&per_page=5000&msisdn=12')
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['sort', 'per_page', 'msisdn']);

        $this->getJson('/api/v1/payments/stk?from=2024-09-01&to=2024-08-01')
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['to']);
    }
}
