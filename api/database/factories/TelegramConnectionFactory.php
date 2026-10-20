<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TelegramConnectionFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory()->owner(), 'chat_id' => (string) fake()->unique()->numberBetween(100000, 999999999), 'connected_at' => now()];
    }
}
