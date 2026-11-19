<?php

namespace Database\Factories;

use App\Enums\MpesaCallbackStatus;
use App\Enums\MpesaCallbackType;
use App\Models\MpesaCallback;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MpesaCallback>
 */
class MpesaCallbackFactory extends Factory
{
    protected $model = MpesaCallback::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => MpesaCallbackType::C2bConfirmation,
            'fingerprint' => hash('sha256', (string) $this->faker->unique()->randomNumber(8)),
            'reference' => strtoupper($this->faker->bothify('T##??#####')),
            'payload' => [],
            'ip_address' => '196.201.214.200',
            'status' => MpesaCallbackStatus::Pending,
            'received_at' => now(),
        ];
    }
}
