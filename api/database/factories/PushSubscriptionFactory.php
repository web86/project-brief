<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PushSubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'project_client_id' => null, 'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.fake()->uuid(), 'public_key' => 'B'.str_repeat('a', 86), 'auth_token' => str_repeat('a', 22), 'content_encoding' => 'aes128gcm'];
    }
}
