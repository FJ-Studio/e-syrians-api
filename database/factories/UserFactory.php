<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Factories\Factory;

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
            'surname' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
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

    /**
     * Indicate that the model has no password on file — social-only signup
     * (Google / Apple, no password ever set). We can't just pass
     * `['password' => null]` at `->create()` time because the model's
     * `password => 'hashed'` cast + the factory's default `Hash::make(...)`
     * conspire to write a non-null hash back to the row. Setting via
     * `afterCreating` + `forceFill(['password' => null])->saveQuietly()`
     * bypasses both — the row on disk really has NULL.
     */
    public function passwordless(): static
    {
        return $this->afterCreating(function (User $user): void {
            $user->forceFill(['password' => null])->saveQuietly();
            $user->refresh();
        });
    }
}
