<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting;

use Faker\Factory;
use Faker\Generator;

/**
 * The one Faker generator that example values are drawn from.
 *
 * Example generation asks for a generator two or three times per parameter,
 * and `Factory::create()` builds ~20 provider objects each time. A generator
 * draws from PHP's global Mersenne Twister, which `seed()` resets, so one
 * instance reseeded on every hand-out yields what a fresh seeded one would.
 * Its only state of its own, the `unique()` history, goes unused.
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

    public static function flushState(): void
    {
        self::$generator = null;
    }
}
