<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting;

use Closure;
use Hypervel\Contracts\Validation\Rule;
use Hypervel\Contracts\Validation\ValidationRule;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Validator;
use Hypervel\Support\Str;
use Hypervel\Validation\ClosureValidationRule;
use Hypervel\Validation\Rules\Enum;
use Ipsocode\Scribe\Exceptions\ProblemParsingValidationRules;
use Ipsocode\Scribe\Exceptions\ScribeException;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils as c;
use ReflectionFunction;
use ReflectionProperty;
use stdClass;
use Throwable;

trait ParsesValidationRules
{
    use ConvertsValidationRules;
    use GeneratesRuleExamples;
    use NormalisesValidationParameters;
    use ParamHelpers;

    public static stdClass $MISSING_VALUE;

    /** A `*` segment of a rule key, with the dot before it unless it comes first. */
    private const WILDCARD_SEGMENT = '/(^|\.)\*(?=\.|$)/';

    /** The `0` that normaliseRules() seeds in place of a `*` segment. */
    private const SEEDED_SEGMENT = '/(^|\.)0(?=\.|$)/';

    /**
     * Resets the using class's own copy of the sentinel. Trait statics are
     * per class, so GetFromFormRequestBase and GetFromInlineValidatorBase each
     * need their own call.
     */
    public static function flushState(): void
    {
        self::$MISSING_VALUE = new stdClass;
    }

    /**
     * @param array<string, mixed> $validationRulesByParameters
     * @param array<string, array<string, mixed>> $customParameterData
     * @return array<string, array<string, mixed>>
     */
    public function getParametersFromValidationRules(array $validationRulesByParameters, array $customParameterData = []): array
    {
        // Write-once: the sentinel is compared by identity (below, and in
        // getParameterExample() and examplePresent()), so reassigning it while
        // another coroutine on the worker is mid-extraction would break that
        // coroutine's comparisons. Only flushState() resets it.
        self::$MISSING_VALUE ??= new stdClass;
        $validationRulesByParameters = $this->normaliseRules($validationRulesByParameters);

        $parameters = [];
        $rulesWhichDependOnType = ['between', 'max', 'min', 'size', 'gt', 'gte', 'lt', 'lte', 'before', 'after', 'before_or_equal', 'after_or_equal'];
        foreach ($validationRulesByParameters as $parameter => $ruleset) {
            $userSpecifiedParameterInfo = $customParameterData[$parameter] ?? [];
            $stringRules = array_filter($ruleset, fn ($rule) => is_string($rule));
            $rulesAndArguments = array_map(fn ($rule) => $this->parseStringRuleIntoRuleAndArguments($rule), $stringRules);

            try {
                $this->warnAboutMissingCustomParameterData($parameter, $customParameterData);

                // Make sure the user-specified description comes first (and add full stops where needed).
                $description = $userSpecifiedParameterInfo['description'] ?? '';
                if (! empty($description) && ! Str::endsWith($description, '.')) {
                    $description .= '.';
                }
                $parameterData = [
                    'name' => $parameter,
                    'required' => false,
                    'sometimes' => false,
                    'type' => null,
                    'example' => self::$MISSING_VALUE,
                    'description' => $description,
                    'nullable' => false,
                ];

                $closureRules = array_filter($ruleset, fn ($rule) => ($rule instanceof ClosureValidationRule || $rule instanceof Closure));
                foreach ($closureRules as $rule) {
                    $this->processClosureRule($rule, $parameterData);
                }

                $enumValidationRules = array_filter($ruleset, fn ($rule) => $rule instanceof Enum);
                foreach ($enumValidationRules as $rule) {
                    $this->processEnumValidationRule($rule, $parameterData);
                }

                $ruleObjects = array_filter($ruleset, fn ($rule) => ($rule instanceof Rule || $rule instanceof ValidationRule));
                foreach ($ruleObjects as $rule) {
                    $this->processRuleObject($rule, $parameterData);
                }

                // String rules are processed in three passes:
                // 1. Presence and comparison rules ($firstPassRuleNames).
                // 2. Everything else, including the rules that set a type.
                // 3. Size and date rules ($rulesWhichDependOnType), once the type is known.
                // 'in' sets no type, but its values are enough for an example.
                $firstPassRuleNames = [
                    'sometimes',
                    'required',
                    'required_*',
                    'accepted',
                    'same',
                    'different',
                    'nullable',
                ];

                $firstPassRules = array_filter($rulesAndArguments, fn ($ruleAndArgs) => Str::is($firstPassRuleNames, $ruleAndArgs[0]));
                foreach ($firstPassRules as $ruleAndArgs) {
                    $this->processRule($ruleAndArgs[0], $ruleAndArgs[1], $parameterData, $validationRulesByParameters);
                }

                // `sometimes` makes a parameter optional wherever it sits in the ruleset.
                if ($parameterData['sometimes']) {
                    $parameterData['required'] = false;
                }

                $secondPassRules = array_filter($rulesAndArguments, fn ($ruleAndArgs) => ! Str::is($firstPassRuleNames, $ruleAndArgs[0]) && ! in_array($ruleAndArgs[0], $rulesWhichDependOnType));
                foreach ($secondPassRules as $ruleAndArgs) {
                    $this->processRule($ruleAndArgs[0], $ruleAndArgs[1], $parameterData, $validationRulesByParameters);
                }

                // Default to string when no rule set a type.
                if (is_null($parameterData['type'])) {
                    $parameterData['type'] = 'string';
                }

                if ($parameterData['required'] === true) {
                    $parameterData['nullable'] = false;
                }

                // The type is known now, so the type-dependent rules can run.
                $thirdPassRules = array_filter($rulesAndArguments, fn ($ruleAndArgs) => in_array($ruleAndArgs[0], $rulesWhichDependOnType));
                foreach ($thirdPassRules as $ruleAndArgs) {
                    $this->processRule($ruleAndArgs[0], $ruleAndArgs[1], $parameterData, $validationRulesByParameters);
                }

                $this->setSizeBoundedSetter($thirdPassRules, $parameterData);

                // A user-specified example overrides the generated one.
                if (array_key_exists('example', $userSpecifiedParameterInfo)) {
                    if ($userSpecifiedParameterInfo['example'] !== null && $this->shouldCastUserExample()) {
                        // Examples written in comments are strings, so cast them to the parameter type.
                        $parameterData['example'] = $this->castToType($userSpecifiedParameterInfo['example'], $parameterData['type'] ?? 'string');
                    } else {
                        $parameterData['example'] = $userSpecifiedParameterInfo['example'];
                    }
                }

                if (! empty($parameterData['description']) && ! Str::endsWith($parameterData['description'], '.')) {
                    $parameterData['description'] .= '.';
                }

                $parameterData['description'] = mb_trim($parameterData['description']);
                $parameters[$parameter] = $parameterData;
            } catch (Throwable $e) {
                if ($e instanceof ScribeException) {
                    // Already wrapped with context; rethrow it as is.
                    throw $e;
                }

                throw ProblemParsingValidationRules::forParam($parameter, $e);
            }
        }

        return $parameters;
    }

    /**
     * Normalizes rules the way the validator does, eg 'param1' => 'int|required'
     * becomes 'param1' => ['int', 'required'], while keeping nested array keys
     * such as 'ids.*' rather than the validator's expanded 'ids.0'.
     *
     * @param array<string,string|string[]> $rules
     * @return array<string, array<mixed>>
     */
    protected function normaliseRules(array $rules): array
    {
        // Validator::make($data, $rules)->getRules() normalizes the rules, but drops a
        // nested array rule (`ids.*`) unless its key (`ids`) holds a non-empty array in
        // the data, so the test data gets a one-item array for each such key.
        $testData = [];
        foreach ($rules as $key => $ruleset) {
            if (! preg_match(self::WILDCARD_SEGMENT, (string) $key)) {
                continue;
            }

            // Only the key's presence matters, not its value.
            Arr::set($testData, preg_replace(self::WILDCARD_SEGMENT, '${1}0', (string) $key), Str::random());
        }

        // The complete ruleset, with '*' in nested array keys replaced by '0'.
        $newRules = Validator::make($testData, $rules)->getRules();

        /** @var array<string, array<mixed>> $normalisedRules */
        $normalisedRules = collect($newRules)->mapWithKeys(function ($val, $paramName) use ($rules) {
            // Turn encoded asterisks back into '*'. The validator encodes an escaped '*'
            // in a key as "__asterisk__" followed by a random placeholder hash (eg
            // "__asterisk__dkjiu78gujjhb"); the pattern also matches a bare "__asterisk__".
            if (Str::contains($paramName, '__asterisk__')) {
                $paramName = preg_replace('/__asterisk__[^.]*\b/', '*', $paramName);
            }

            // Turn 'ids.0' (or a top-level '0.name') back into 'ids.*' ('*.name')
            if (preg_match(self::SEEDED_SEGMENT, (string) $paramName)) {
                $genericArrayKeyName = preg_replace(self::SEEDED_SEGMENT, '${1}*', (string) $paramName);

                // But only if the original rules used that key
                if (isset($rules[$genericArrayKeyName])) {
                    $paramName = $genericArrayKeyName;
                }
            }

            return [$paramName => $val];
        })->toArray();

        return $normalisedRules;
    }

    /**
     * Appends the docblock written above an inline closure rule to the parameter
     * description, eg one reading "Must be a hexadecimal number." above
     * `function ($attribute, $value, $fail) { ... }` in a rules array.
     *
     * @param Closure|ClosureValidationRule $rule
     * @param array<string, mixed> $parameterData
     */
    protected function processClosureRule($rule, array &$parameterData): void
    {
        // ClosureValidationRule keeps $callback protected, so it is read through reflection.
        $callable = $rule instanceof ClosureValidationRule
            ? (new ReflectionProperty(ClosureValidationRule::class, 'callback'))->getValue($rule)
            : $rule;

        $docComment = (new ReflectionFunction($callable))->getDocComment();

        if (is_string($docComment)) {
            $description = '';
            foreach (explode("\n", $docComment) as $line) {
                $cleaned = preg_replace(['/\*+\/$/', '/^\/\*+\s*/', '/^\*+\s*/'], '', mb_trim($line));
                if ($cleaned !== '') {
                    $description .= ' ' . $cleaned;
                }
            }

            $parameterData['description'] .= $description;
        }
    }

    /**
     * @param object $rule
     * @param array<string, mixed> $parameterData
     */
    protected function processRuleObject($rule, array &$parameterData): void
    {
        if (method_exists($rule, 'invokable')) {
            // The validator wraps a ValidationRule object in an InvokableValidationRule; unwrap it.
            $rule = $rule->invokable();
        }

        // Users can define a custom "docs" method on a rule to give Scribe more info.
        if (method_exists($rule, 'docs')) {
            /** @var array<string, mixed> $customData */
            // @phpstan-ignore argument.type (method_exists() has just confirmed the rule declares docs())
            $customData = call_user_func_array([$rule, 'docs'], []) ?: [];

            if (isset($customData['description'])) {
                $parameterData['description'] .= ' ' . $customData['description'];
            }
            if (isset($customData['example'])) {
                // An explicit example beats every setter the rules after this one leave.
                $parameterData['example'] = $customData['example'];
            } elseif (isset($customData['setter'])) {
                $parameterData['setter'] = $customData['setter'];
            }

            /** @var array<string, mixed> $extraData */
            $extraData = Arr::except($customData, [
                'description', 'example', 'setter',
            ]);
            $parameterData = array_merge($parameterData, $extraData);
        }
    }

    /**
     * Splits a string rule of the form {rule}:{arguments} into its lowercased name
     * and comma-separated arguments, eg "in:1,2" becomes ["in", ["1", "2"]].
     *
     * @return array{string, array<int, string>}
     */
    protected function parseStringRuleIntoRuleAndArguments(string $rule): array
    {
        $ruleArguments = [];

        if (str_contains($rule, ':')) {
            [$rule, $argumentsString] = explode(':', $rule, 2);

            // These rules' arguments can contain commas, so they are not split.
            if (in_array(mb_strtolower($rule), ['regex', 'date', 'date_format'])) {
                $ruleArguments = [$argumentsString];
            } else {
                $ruleArguments = str_getcsv($argumentsString, escape: '\\');
            }
        }

        return [mb_strtolower(mb_trim($rule)), $ruleArguments];
    }

    /**
     * @param array<string, string> $arguments
     */
    protected function getDescription(string $rule, array $arguments = [], string $baseType = 'string'): string
    {
        if ($rule === 'regex') {
            return "Must match the regex {$arguments[':regex']}.";
        }

        $translationString = "validation.{$rule}";
        $description = trans($translationString);

        // A rule that applies to several types (eg 'max') has one message per type, eg
        // 'numeric' => 'The :attribute must not be greater than :max'
        // 'file' => 'The :attribute must have a size less than :max kilobytes'
        // trans() returns either that array or the untranslated key, in which case the
        // key is retried with the base type appended.
        if ($description === $translationString) {
            $translationString = "{$translationString}.{$baseType}";
            $translated = trans($translationString);
            if ($translated !== $translationString) {
                $description = $translated;
            }
        } elseif (is_array($description)) {
            $description = $description[$baseType];
        }

        // Convert messages from failure type ("The :attribute is not a valid date.") to info ("The :attribute must be a valid date.")
        /** @var string $description a leaf translation key is a string, and the per-type array is resolved above */
        $description = str_replace(['is not', 'does not'], ['must be', 'must'], $description);
        $description = str_replace('may not', 'must not', $description);

        foreach ($arguments as $placeholder => $argument) {
            $description = str_replace($placeholder, $argument, $description);
        }

        // The validator's messages read "The :attribute field must ..."; describe the value instead.
        $description = str_replace('The :attribute field ', 'The value ', $description);

        $description = preg_replace('/(?!<\W):attribute\b/', 'value', $description);

        return str_replace(
            ['The value must ', ' 1 characters', ' 1 digits', ' 1 kilobytes'],
            ['Must ', ' 1 character', ' 1 digit', ' 1 kilobyte'],
            $description
        );
    }

    protected function getMissingCustomDataMessage(string $parameterName): string
    {
        return '';
    }

    protected function shouldCastUserExample(): bool
    {
        return false;
    }

    /**
     * @param array<string, array<string, mixed>> $customParameterData
     * @return array<string, array<string, mixed>>
     */
    protected function warnAboutMissingCustomParameterData(string $parameter, array $customParameterData): array
    {
        $parameterPlusDot = $parameter . '.';
        if (count($customParameterData) && ! isset($customParameterData[$parameter])
            && ! Arr::first(array_keys($customParameterData), fn ($key) => str_starts_with($key, $parameterPlusDot))
        ) {
            c::debug($this->getMissingCustomDataMessage($parameter));
        }

        return $customParameterData;
    }

    private function getHypervelValidationBaseTypeMapping(string $parameterType): string
    {
        $mapping = [
            'number' => 'numeric',
            'integer' => 'numeric',
            'file' => 'file',
            'string' => 'string',
            'array' => 'array',
        ];

        if (Str::endsWith($parameterType, '[]')) {
            return 'array';
        }

        return $mapping[$parameterType] ?? 'string';
    }
}
