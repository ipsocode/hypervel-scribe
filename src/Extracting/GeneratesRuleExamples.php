<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting;

use Closure;
use Hypervel\Validation\Rules\Enum;
use ReflectionClass;
use ReflectionProperty;
use UnitEnum;

/**
 * Builds the examples a validation rule's bounds and options allow: the size
 * bounded setter, `regex` delimiters and the cases of an `Enum` rule. Used by
 * ParsesValidationRules.
 */
trait GeneratesRuleExamples
{
    /**
     * Builds one example generator from all of a parameter's size rules, so that the
     * example satisfies every bound together.
     *
     * A format rule (`email`, `url`, `uuid`, `regex`, ...) leaves a setter whose examples
     * the validator accepts, so that setter is kept and used whenever its example fits the
     * bounds. Only when it does not fit does the bounded generator take over.
     *
     * @param array<int, array{string, array<int, mixed>}> $rulesAndArguments
     * @param array<string, mixed> $parameterData
     */
    protected function setSizeBoundedSetter(array $rulesAndArguments, array &$parameterData): void
    {
        // The setter of a plain type rule (`string`, `integer`, ...) knows nothing the bounded
        // generator does not, so only a setter that some other rule left counts as a format.
        $formatSetter = $parameterData['setter'] ?? null;
        if ($formatSetter === ($parameterData['typeSetter'] ?? null)) {
            $formatSetter = null;
        }
        unset($parameterData['typeSetter']);

        $min = $max = $size = null;
        foreach ($rulesAndArguments as [$rule, $arguments]) {
            switch ($rule) {
                case 'size':
                    $size = (float) $arguments[0];

                    break;
                case 'min':
                    $min = (float) $arguments[0];

                    break;
                case 'max':
                    $max = (float) $arguments[0];

                    break;
                case 'between':
                    $min = (float) $arguments[0];
                    $max = (float) $arguments[1];

                    break;
            }
        }

        if (is_null($min) && is_null($max)) {
            if (! is_null($size)) {
                $this->preferFormatSetter(
                    $parameterData,
                    $formatSetter,
                    $this->getDummyValueGenerator($parameterData['type'], ['size' => $size]),
                    $size,
                    $size
                );
            }

            return;
        }

        $lower = $min ?? min(1.0, $max);
        // Keep the example small: long strings and large arrays are slow to generate.
        $upper = min($max ?? $lower + 10, $lower + 24);
        $this->preferFormatSetter(
            $parameterData,
            $formatSetter,
            $this->getDummyDataGeneratorBetween($parameterData['type'], $lower, $upper, $parameterData['name']),
            $min,
            $max
        );
    }

    /**
     * Sets the parameter's setter to the format rule's, falling back to the bounded one
     * when the format's example is outside the bounds.
     *
     * @param array<string, mixed> $parameterData
     */
    private function preferFormatSetter(array &$parameterData, ?Closure $formatSetter, Closure $bounded, ?float $min, ?float $max): void
    {
        $type = $parameterData['type'];

        // A file example is built, not measured, so it always comes from the bounded generator.
        if (is_null($formatSetter) || $type === 'file') {
            $parameterData['setter'] = $bounded;

            return;
        }

        $parameterData['setter'] = function () use ($formatSetter, $bounded, $type, $min, $max) {
            $example = $formatSetter();

            return $this->fitsSizeBounds($type, $example, $min, $max) ? $example : $bounded();
        };
    }

    /**
     * Whether an example meets the bounds the way the validator measures it: a number by
     * its value, a string by its length. Any other example counts as not fitting.
     */
    private function fitsSizeBounds(string $type, mixed $example, ?float $min, ?float $max): bool
    {
        if ($type === 'integer' || $type === 'number') {
            $measure = is_numeric($example) ? (float) $example : null;
        } else {
            $measure = is_string($example) ? (float) mb_strlen($example) : null;
        }

        return ! is_null($measure)
            && (is_null($min) || $measure >= $min)
            && (is_null($max) || $measure <= $max);
    }

    /**
     * Applies the rule's `only()` and `except()` lists to an enum's cases; `only()` wins, as in the rule itself.
     *
     * @param array<int, UnitEnum> $cases
     * @return array<int, UnitEnum>
     */
    protected function filterEnumCases(Enum $rule, array $cases): array
    {
        $only = (new ReflectionProperty(Enum::class, 'only'))->getValue($rule);
        $except = (new ReflectionProperty(Enum::class, 'except'))->getValue($rule);

        if (! empty($only)) {
            return array_values(array_filter($cases, fn ($case) => in_array($case, $only, true)));
        }

        return array_values(array_filter($cases, fn ($case) => ! in_array($case, $except, true)));
    }

    /**
     * Takes the delimiters and trailing flags off a `regex:` argument, eg `/^[a-z]{3}$/i` becomes `^[a-z]{3}$`.
     */
    protected function stripRegexDelimiters(string $pattern): string
    {
        $opening = $pattern[0] ?? '';
        $closing = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'][$opening] ?? $opening;
        $end = mb_strrpos($pattern, $closing);

        if ($opening === '' || ctype_alnum($opening) || $end === false || $end === 0) {
            return $pattern;
        }

        return mb_substr($pattern, 1, $end - 1);
    }

    /**
     * @param array<string, mixed> $parameterData
     * @param array<string, mixed> $allParameters
     */
    protected function processEnumValidationRule(Enum $rule, array &$parameterData, array $allParameters = []): void
    {
        $property = (new ReflectionClass($rule))->getProperty('type');
        $property->setAccessible(true);
        $enumClass = $property->getValue($rule);

        if (enum_exists($enumClass) && method_exists($enumClass, 'tryFrom')) {
            // Only a BackedEnum has ->value, and only a BackedEnum has tryFrom().
            // @phpstan-ignore property.notFound (a tryFrom() enum is a BackedEnum, which has ->value)
            $cases = array_map(fn ($case) => $case->value, $this->filterEnumCases($rule, $enumClass::cases()));
            if ($cases === []) {
                return;
            }

            $parameterData['type'] = gettype($cases[0]);
            $parameterData['enumValues'] = $cases;
            // Seeded getFaker() keeps the picked example stable across doc runs
            // (Arr::random() uses random_int(), which examples.faker_seed can't fix).
            $parameterData['setter'] = fn () => $this->getFaker()->randomElement($cases);
        }
    }
}
