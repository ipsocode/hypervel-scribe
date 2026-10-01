<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Support\Facades\Hash;
use Hypervel\Support\Str;
use Workbench\App\Models\User;

class UserFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<User>
     */
    protected ?string $model = User::class;

    /**
     * The bcrypt hash of the password every example user shares.
     *
     * Hashing is deliberately expensive, and Scribe instantiates an example
     * model for every API-resource response it documents — a paginated
     * collection alone is one `create()` per row. Hashing the same literal
     * once per process instead of once per user takes the suite's dominant
     * cost out of the extraction path.
     */
    protected static ?string $password = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
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
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }
}
