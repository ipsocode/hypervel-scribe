<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting;

use Closure;
use Faker\Generator;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Arr;
use Hypervel\Support\Str;

trait ParamHelpers
{
    /**
     * Maps a stated type such as "int", "double" or "bool[]" to its JSON type
     * name ("integer", "number", "boolean[]"). Other types are returned as is,
     * and an empty one becomes "string".
     *
     * @param mixed $value an example value, which decides whether "array" becomes X[] or object
     */
    public static function normalizeTypeName(?string $typeName, $value = null): string
    {
        if (! $typeName) {
            return 'string';
        }

        $base = str_replace('[]', '', mb_strtolower($typeName));

        return match ($base) {
            'bool' => str_replace($base, 'boolean', $typeName),
            'int' => str_replace($base, 'integer', $typeName),
            'float', 'double' => str_replace($base, 'number', $typeName),
            'array' => (empty($value) || array_keys($value)[0] === 0)
                ? static::normalizeTypeName(gettype($value[0] ?? '')) . '[]'
                : 'object',
            default => $typeName
        };
    }

    protected function getFakeFactoryByName(string $name): ?Closure
    {
        $faker = $this->getFaker();

        $name = mb_strtolower(array_reverse(explode('.', $name))[0]);
        $normalizedName = match (true) {
            Str::endsWith($name, ['email', 'email_address']) => 'email',
            Str::endsWith($name, ['uuid']) => 'uuid',
            Str::endsWith($name, ['url']) => 'url',
            Str::endsWith($name, ['locale']) => 'locale',
            Str::endsWith($name, ['timezone']) => 'timezone',
            default => $name,
        };

        return match ($normalizedName) {
            'email' => fn () => $faker->safeEmail(),
            'password', 'pwd' => fn () => $faker->password(),
            'url' => fn () => $faker->url(),
            'description' => fn () => $faker->sentence(),
            'uuid' => fn () => $faker->uuid(),
            'locale' => fn () => $faker->locale(),
            'timezone' => fn () => $faker->timezone(),
            default => null,
        };
    }

    protected function getFaker(): Generator
    {
        return SeededFaker::get($this->config->get('examples.faker_seed'));
    }

    protected function generateDummyValue(string $type, array $hints = [])
    {
        if (! empty($hints['enumValues'])) {
            return Arr::random($hints['enumValues']);
        }

        $fakeFactory = $this->getDummyValueGenerator($type, $hints);

        return $fakeFactory();
    }

    protected function getDummyValueGenerator(string $type, array $hints = []): Closure
    {
        $baseType = $type;
        $isListType = false;

        if (Str::endsWith($type, '[]')) {
            $baseType = mb_strtolower(mb_substr($type, 0, mb_strlen($type) - 2));
            $isListType = true;
        }

        $size = $hints['size'] ?? null;
        if ($isListType) {
            // A list example holds a single item.
            return $size
                ? fn () => [$this->generateDummyValue($baseType, range(0, min($size - 1, 5)))]
                : fn () => [$this->generateDummyValue($baseType, $hints)];
        }

        if (($hints['name'] ?? false) && $baseType !== 'file') {
            $fakeFactoryByName = $this->getFakeFactoryByName($hints['name']);
            if ($fakeFactoryByName) {
                return $fakeFactoryByName;
            }
        }

        $faker = $this->getFaker();
        $min = $hints['min'] ?? null;
        $max = $hints['max'] ?? null;
        // A min or max, when given, takes precedence over size.
        $isExactSize = is_null($min) && is_null($max) && ! is_null($size);

        $fakeFactoriesByType = [
            'integer' => function () use ($size, $isExactSize, $max, $faker, $min) {
                if ($isExactSize) {
                    return $size;
                }

                return $max ? $faker->numberBetween((int) $min, (int) $max) : $faker->numberBetween(1, 20);
            },
            'number' => function () use ($size, $isExactSize, $max, $faker, $min) {
                if ($isExactSize) {
                    return $size;
                }

                return $max ? $faker->numberBetween((int) $min, (int) $max) : $faker->randomFloat();
            },
            'boolean' => fn () => $faker->boolean(),
            'string' => fn () => $size ? $faker->lexify(str_repeat('?', (int) $size)) : $faker->word(),
            'object' => fn () => [],
            'file' => fn () => UploadedFile::fake()->create('test.jpg')->size($size ?: 10),
        ];

        return $fakeFactoriesByType[$baseType] ?? $fakeFactoriesByType['string'];
    }

    protected function isSupportedTypeInDocBlocks(string $type): bool
    {
        $types = [
            'integer',
            'int',
            'number',
            'float',
            'double',
            'boolean',
            'bool',
            'string',
            'object',
        ];

        return in_array(str_replace('[]', '', $type), $types);
    }

    protected function castToType($value, string $type)
    {
        if ($value === null) {
            return null;
        }

        if ($type === 'array') {
            $type = 'string[]';
        }

        if (Str::endsWith($type, '[]')) {
            $baseType = mb_strtolower(mb_substr($type, 0, mb_strlen($type) - 2));

            return is_array($value) ? array_map(function ($v) use ($baseType) {
                return $this->castToType($v, $baseType);
            }, $value) : json_decode($value);
        }

        if ($type === 'object') {
            return is_array($value) ? $value : json_decode($value, true);
        }

        $casts = [
            'integer' => 'intval',
            'int' => 'intval',
            'float' => 'floatval',
            'number' => 'floatval',
            'double' => 'floatval',
            'boolean' => 'boolval',
            'bool' => 'boolval',
        ];

        // boolval('false') is true, so the string 'false' is handled first.
        if ($value === 'false' && ($type === 'boolean' || $type === 'bool')) {
            return false;
        }

        if (isset($casts[$type])) {
            return $casts[$type]($value);
        }

        return $value;
    }

    /**
     * Whether the description asks for no example by containing " No-example".
     */
    protected function shouldExcludeExample(string $description): bool
    {
        return mb_strpos($description, ' No-example') !== false;
    }

    /**
     * Takes a trailing "Example: ..." and "Enum: a, b" off a parameter description,
     * casting the values to the parameter type.
     *
     * @param string $type the parameter type, which the example and enum values are cast to
     * @return array the description, the example, the enum values, and whether an example was given
     */
    protected function parseExampleFromParamDescription(string $description, string $type): array
    {
        $exampleWasSpecified = false;
        $example = null;
        $enumValues = [];

        if (preg_match('/(.*)\bExample:\s*([\s\S]+)\s*/s', $description, $content)) {
            $exampleWasSpecified = true;
            $description = mb_trim($content[1]);

            if ($content[2] === 'null') {
                $example = null;
            } else {
                // The example is parsed as a string; cast it to the parameter type.
                $example = $this->castToType($content[2], $type);
            }
        }

        if (preg_match('/(.*)\bEnum:\s*([\s\S]+)\s*/s', $description, $content)) {
            $description = mb_trim($content[1]);

            $enumValues = array_map(
                fn ($value) => $this->castToType(mb_trim($value), $type),
                explode(',', mb_rtrim(mb_trim($content[2]), '.'))
            );
        }

        return [$description, $example, $enumValues, $exampleWasSpecified];
    }

    private function getDummyDataGeneratorBetween(string $type, $min, $max = 90, ?string $fieldName = null): Closure
    {
        $hints = [
            'name' => $fieldName,
            'size' => $this->getFaker()->numberBetween($min, $max),
            'min' => $min,
            'max' => $max,
        ];

        return $this->getDummyValueGenerator($type, $hints);
    }
}
