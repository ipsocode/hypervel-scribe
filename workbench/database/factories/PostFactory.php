<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Hypervel\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Post;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Post>
     */
    protected ?string $model = Post::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'published' => false,
        ];
    }

    /**
     * A post that is visible to the public.
     *
     * Named states are what `#[ResponseFromApiResource(factoryStates: [...])]`
     * addresses, so at least one has to exist for that path to be testable.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'published' => true,
        ]);
    }

    /**
     * Pivot attributes for the `tags` relation.
     *
     * Scribe looks for a `pivot<Relation>` method when building a
     * belongs-to-many through `hasAttached()`; without one the pivot row is
     * created with defaults only.
     *
     * @return array<string, mixed>
     */
    public function pivotTags(): array
    {
        return ['added_by' => 'scribe'];
    }
}
