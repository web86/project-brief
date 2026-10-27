<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'role' => 'user',
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function owner(?Workspace $workspace = null, bool $secured = true): static
    {
        $factory = $this->memberOf($workspace ?? Workspace::factory()->create(), 'owner');
        if (! $secured) {
            return $factory;
        }

        // Business tests use enrolled owners; security tests explicitly request an unenrolled owner.
        return $factory->afterCreating(function (User $user): void {
            $user->forceFill([
                'two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey()),
                'two_factor_confirmed_at' => now(),
                'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['test-recovery-code'])),
                'recovery_codes_acknowledged_at' => now(),
            ])->save();
            $user->passkeys()->create(['name' => 'Fixture credential', 'credential_id' => bin2hex(random_bytes(32)), 'credential' => []]);
        });
    }

    public function memberOf(Workspace $workspace, string $role = 'admin'): static
    {
        return $this->afterCreating(fn (User $user) => $workspace->members()->create([
            'user_id' => $user->id, 'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]));
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
