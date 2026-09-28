<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting;

use Faker\Factory;
use Faker\Generator;

/**
 * The one Faker generator that example values are drawn from.
 *
 * `Factory::create()` builds a generator plus a provider object for each of
 * Faker's ~20 default providers, and example generation asks for a generator
 * for every value — two or three times per parameter. A Faker generator keeps
 * no random state of its own: it draws from PHP's global Mersenne Twister,
 * which `seed()` resets. So one instance, reseeded on every hand-out, yields
 * the same values a freshly created and seeded one would. The only
 * per-instance state a generator does keep, the `unique()` history, is never
 * used by Scribe.
 */
final class SeededFaker
{
    private static ?Generator $generator = null;

    /**
     * The shared generator, reseeded with $seed when one is configured.
     *
     * A falsy seed leaves the sequence where it is, as a freshly created
     * generator would: `examples.faker_seed` of null or 0 means unseeded.
     */
    public static function get(mixed $seed): Generator
    {
        $faker = self::$generator ??= Factory::create();

        if ($seed) {
            $faker->seed($seed);
        }

        return $faker;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        self::$generator = null;
    }
}
