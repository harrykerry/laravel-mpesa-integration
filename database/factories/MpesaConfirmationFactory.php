<?php

namespace Database\Factories;

use App\Models\MpesaConfirmation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MpesaConfirmation>
 */
class MpesaConfirmationFactory extends Factory
{
    protected $model = MpesaConfirmation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_type' => 'Pay Bill',
            'transaction_id' => strtoupper($this->faker->bothify('T##??#####')),
            'transaction_time' => now()->format('YmdHis'),
            'transaction_amount' => $this->faker->numberBetween(10, 50_000),
            'business_shortcode' => '600000',
            'billref_no' => strtoupper($this->faker->bothify('ACC###')),
            'invoice_no' => 'NA',
            'org_balance' => $this->faker->numberBetween(1_000, 500_000),
            'thirdparty_transid' => 'NA',
            'mobile_number' => '2547'.$this->faker->numerify('########'),
            'first_name' => $this->faker->firstName(),
            'middle_name' => 'NA',
            'last_name' => $this->faker->lastName(),
        ];
    }
}
