<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting;

use Hypervel\Support\Arr;
use Hypervel\Support\Str;

/**
 * Turns the validator's `.*` array notation into Scribe's `[]` types and sets
 * each parameter's example. Used by ParsesValidationRules.
 */
trait NormalisesValidationParameters
{
    /**
     * Converts the validator's `.*` array notation into Scribe's `[]` types and
     * sets each parameter's example.
     *
     * 'years.*' with type 'integer' becomes 'years' with type 'integer[]'
     * 'cars.*.age' with type 'string' becomes 'cars[].age' with type 'string' and 'cars' with type 'object[]'
     * 'cars.*.things.*.*' with type 'string' becomes 'cars[].things' with type 'string[][]' and 'cars' with type
     * 'object[]'
     *
     * A subfield declared without its parent also gets a parameter for the parent.
     *
     * @param array<string, array<string, mixed>> $parametersFromValidationRules
     * @return array<string, array<string, mixed>>
     */
    public function normaliseArrayAndObjectParameters(array $parametersFromValidationRules): array
    {
        // Convert any `array` types into concrete types like `object[]`, object, or `string[]`
        $parameters = $this->convertGenericArrayType($parametersFromValidationRules);

        $parameters = $this->convertArraySubfields($parameters);

        // Add the fields `cars.*.dogs` and `cars` if they don't exist
        $parameters = $this->addMissingParentFields($parameters);

        return $this->setExamples($parameters);
    }

    /**
     * @param array<string, array<string, mixed>> $parameters
     * @return array<string, array<string, mixed>>
     */
    public function convertGenericArrayType(array $parameters): array
    {
        $converted = [];
        $allKeys = array_keys($parameters);
        foreach (array_reverse($parameters) as $name => $details) {
            if ($details['type'] === 'array') {
                // A parent field with the generic `array` type. Scribe only supports concrete
                // array types (T[]), so for a key "users" of type "array":
                // 1. If `users.*.<field>` exists, `users` is `object[]`.
                // 2. If `users.*` exists, `users` is `X[]`, where X is the type of `users.*`.
                // 3. Otherwise `users` is `object`.
                // Iterating in reverse converts children before their parents, given the
                // usual order of parents listed first.
                if (Arr::first($allKeys, fn ($key) => Str::startsWith($key, "{$name}.*."))) {
                    $details['type'] = 'object[]';
                    unset($details['setter']);
                } elseif ($childKey = Arr::first($allKeys, fn ($key) => Str::startsWith($key, "{$name}.*"))) {
                    $childType = ($converted[$childKey] ?? $parameters[$childKey])['type'];
                    $details['type'] = "{$childType}[]";
                } else {
                    $details['type'] = 'object';
                    unset($details['setter']);
                }
            }

            $converted[$name] = $details;
        }

        // Write the converted entries back in the original key order.
        foreach ($allKeys as $key) {
            $parameters[$key] = $converted[$key] ?? $parameters[$key];
        }

        return $parameters;
    }

    /**
     * @param array<string, array<string, mixed>> $parameters
     * @return array<string, array<string, mixed>>
     */
    public function convertArraySubfields(array $parameters): array
    {
        $results = [];
        foreach ($parameters as $name => $details) {
            $isSubfield = Str::endsWith($name, '.*');
            if ($isSubfield) {
                // The user might have set the example via bodyParameters()
                $exampleWasSpecified = $this->examplePresent($details);

                // Change cars.*.dogs.things.*.* with type X to cars.*.dogs.things with type X[][]
                while (Str::endsWith($name, '.*')) {
                    $details['type'] .= '[]';
                    $name = mb_substr($name, 0, -2);

                    if ($exampleWasSpecified) {
                        $details['example'] = [$details['example']];
                    } elseif (isset($details['setter'])) {
                        $previousSetter = $details['setter'];
                        $details['setter'] = fn () => [$previousSetter()];
                    }
                }
            }

            if (isset($results[$name])) {
                // 'tags' and 'tags.*' both end up as 'tags': the parent keeps its own rules, description and example.
                $results[$name] = $isSubfield
                    ? $this->mergeArrayAndSubfield($results[$name], $details)
                    : $this->mergeArrayAndSubfield($details, $results[$name]);
            } else {
                $results[$name] = $details;
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $parent
     * @param array<string, mixed> $subfield the converted `.*` entry, already typed as a list
     * @return array<string, mixed>
     */
    protected function mergeArrayAndSubfield(array $parent, array $subfield): array
    {
        $parent['type'] = $subfield['type'];
        unset($parent['setter']);
        if (isset($subfield['setter'])) {
            $parent['setter'] = $subfield['setter'];
        }

        if (! $this->examplePresent($parent) && $this->examplePresent($subfield)) {
            $parent['example'] = $subfield['example'];
        }

        return $parent;
    }

    /**
     * @param array<string, array<string, mixed>> $parameters
     * @return array<string, array<string, mixed>>
     */
    public function setExamples(array $parameters): array
    {
        $examples = [];

        foreach ($parameters as $name => $details) {
            if ($this->examplePresent($details)) {
                // Record examples already present (eg from bodyParameters()), so that
                // 'data' => ['example' => ['title' => 'A title']] also provides the
                // example for `data.title`. This assumes parents are listed before
                // their children.
                $examples[$details['name']] = $details['example'];
            } elseif (preg_match('/.+\.[^*]+$/', $details['name'])) {
                // An object field (eg 'data.details.title') takes its example from its parent's, if recorded.
                $dot = mb_strrpos($details['name'], '.');
                // @phpstan-ignore argument.type (the regex above has matched, so the name holds a dot)
                $parentName = mb_substr($details['name'], 0, $dot);
                $fieldName = mb_substr($details['name'], $dot + 1);
                if (array_key_exists($parentName, $examples) && is_array($examples[$parentName])
                    && array_key_exists($fieldName, $examples[$parentName])) {
                    $examples[$details['name']] = $details['example'] = $examples[$parentName][$fieldName];
                }
            }

            $details['example'] = $this->getParameterExample($details);
            unset($details['setter']);

            $parameters[$name] = $details;
        }

        return $parameters;
    }

    /**
     * @param array<string, mixed> $parameterData
     */
    protected function getParameterExample(array $parameterData): mixed
    {
        // "No-example" hides the example of an optional parameter. A required one still
        // needs a value for the response call, so it is treated as having no example.
        if ($parameterData['example'] === 'No-example') {
            if (! $parameterData['required']) {
                return null;
            }

            $parameterData['example'] = self::$MISSING_VALUE;
        }

        // No example was given: use the setter the last processed rule left, or
        // a generated value if the parameter is required.
        if ($parameterData['example'] === self::$MISSING_VALUE) {
            if (isset($parameterData['setter'])) {
                return $parameterData['setter']();
            }

            return $parameterData['required']
                ? $this->generateDummyValue($parameterData['type'])
                : null;
        }
        if (! is_null($parameterData['example']) && $parameterData['example'] !== self::$MISSING_VALUE) {
            // Cast again, since the validator may have turned the value into a string.
            return $this->castToType($parameterData['example'], $parameterData['type']);
        }

        return $parameterData['example'] === self::$MISSING_VALUE ? null : $parameterData['example'];
    }

    /**
     * @param array<string, array<string, mixed>> $parameters
     * @return array<string, array<string, mixed>>
     */
    protected function addMissingParentFields(array $parameters): array
    {
        $results = [];
        foreach ($parameters as $name => $details) {
            if (isset($results[$name])) {
                continue;
            }

            $parentPath = $name;
            while (Str::contains($parentPath, '.')) {
                $parentPath = preg_replace('/\.[^.]+$/', '', $parentPath);
                $normalisedParentPath = str_replace('.*.', '[].', $parentPath);

                if (empty($results[$normalisedParentPath])) {
                    if (Str::endsWith($parentPath, '.*')) {
                        $parentPath = mb_substr($parentPath, 0, -2);
                        $normalisedParentPath = str_replace('.*.', '[].', $parentPath);

                        if (! empty($results[$normalisedParentPath])) {
                            break;
                        }

                        $type = 'object[]';
                        $example = [[]];
                    } else {
                        $type = 'object';
                        $example = [];
                    }
                    $results[$normalisedParentPath] = [
                        'name' => $normalisedParentPath,
                        'type' => $type,
                        'required' => false,
                        'description' => '',
                        'example' => $example,
                    ];
                }
            }

            $details['name'] = $name = str_replace('.*.', '[].', $name);

            if (isset($parameters[$details['name']]) && $this->examplePresent($parameters[$details['name']])) {
                $details['example'] = $parameters[$details['name']]['example'];
            }

            $results[$name] = $details;
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $parameterData
     */
    private function examplePresent(array $parameterData): bool
    {
        return isset($parameterData['example']) && $parameterData['example'] !== self::$MISSING_VALUE;
    }
}
