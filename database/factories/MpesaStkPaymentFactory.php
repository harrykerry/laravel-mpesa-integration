<?php

namespace Database\Factories;

use App\Models\MpesaStkPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MpesaStkPayment>
 */
class MpesaStkPaymentFactory extends Factory
{
    protected $model = MpesaStkPayment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_request_id' => $this->faker->numerify('#####-#######-#'),
            'checkout_request_id' => 'ws_CO_'.$this->faker->numerify('##############'),
            'transaction_id' => null,
            'transaction_date' => null,
            'amount' => null,
            'msisdn' => null,
            'business_shortcode' => '174379',
        ];
    }

    /**
     * A push M-PESA has confirmed with a receipt.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'transaction_id' => strtoupper($this->faker->bothify('S##??#####')),
            'transaction_date' => now()->format('YmdHis'),
            'amount' => $this->faker->numberBetween(10, 50_000),
            'msisdn' => '2547'.$this->faker->numerify('########'),
        ]);
    }
}
