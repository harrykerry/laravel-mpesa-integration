<?php

namespace Database\Factories;

use App\Enums\MpesaRequestStatus;
use App\Enums\MpesaRequestType;
use App\Models\MpesaRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MpesaRequest>
 */
class MpesaRequestFactory extends Factory
{
    protected $model = MpesaRequest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => MpesaRequestType::B2c,
            'conversation_id' => 'AG_'.$this->faker->numerify('########')."_000{$this->faker->numerify('####')}",
            'originator_conversation_id' => $this->faker->numerify('#####-########-#'),
            'reference' => '2547'.$this->faker->numerify('########'),
            'amount' => $this->faker->numberBetween(100, 50_000),
            'status' => MpesaRequestStatus::Accepted,
            'request' => [],
        ];
    }
}
