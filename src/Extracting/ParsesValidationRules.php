<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting;

use Closure;
use Hypervel\Contracts\Validation\Rule;
use Hypervel\Contracts\Validation\ValidationRule;
use Hypervel\Support\Arr;
use Hypervel\Support\Carbon;
use Hypervel\Support\Facades\Validator;
use Hypervel\Support\Str;
use Hypervel\Validation\ClosureValidationRule;
use Hypervel\Validation\Rules\Enum;
use Ipsocode\Scribe\Exceptions\CouldntProcessValidationRule;
use Ipsocode\Scribe\Exceptions\ProblemParsingValidationRules;
use Ipsocode\Scribe\Exceptions\ScribeException;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils as c;
use Ipsocode\Scribe\Tools\WritingUtils as w;
use ReflectionClass;
use ReflectionFunction;
use ReflectionProperty;
use stdClass;
use Throwable;

trait ParsesValidationRules
{
    use ParamHelpers;

    public static stdClass $MISSING_VALUE;

    /**
     * Resets the using class's own copy of the sentinel. Trait statics are
     * per class, so GetFromFormRequestBase and GetFromInlineValidatorBase each
     * need their own call.
     */
    public static function flushState(): void
    {
        self::$MISSING_VALUE = new stdClass;
    }

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
     * @param array[] $parametersFromValidationRules
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

    public function convertArraySubfields(array $parameters): array
    {
        $results = [];
        foreach ($parameters as $name => $details) {
            if (Str::endsWith($name, '.*')) {
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

            $results[$name] = $details;
        }

        return $results;
    }

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
                [$parentName, $fieldName] = preg_split('/\.(?=[\w-]+$)/', $details['name']);
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
     * Normalises rules the way the validator does, eg 'param1' => 'int|required'
     * becomes 'param1' => ['int', 'required'], while keeping nested array keys
     * such as 'ids.*' rather than the validator's expanded 'ids.0'.
     *
     * @param array<string,string|string[]> $rules
     */
    protected function normaliseRules(array $rules): array
    {
        // Validator::make($data, $rules)->getRules() normalises the rules, but drops a
        // nested array rule (`ids.*`) unless its key (`ids`) holds a non-empty array in
        // the data, so the test data gets a one-item array for each such key.
        $testData = [];
        foreach ($rules as $key => $ruleset) {
            if (! Str::contains($key, '.*')) {
                continue;
            }

            // Only the key's presence matters, not its value.
            Arr::set($testData, str_replace('.*', '.0', $key), Str::random());
        }

        // The complete ruleset, with '*' in nested array keys replaced by '0'.
        $newRules = Validator::make($testData, $rules)->getRules();

        return collect($newRules)->mapWithKeys(function ($val, $paramName) use ($rules) {
            // Turn encoded asterisks back into '*'. The validator encodes an escaped '*'
            // in a key as "__asterisk__" followed by a random placeholder hash (eg
            // "__asterisk__dkjiu78gujjhb"); the pattern also matches a bare "__asterisk__".
            if (Str::contains($paramName, '__asterisk__')) {
                $paramName = preg_replace('/__asterisk__[^.]*\b/', '*', $paramName);
            }

            // Turn 'ids.0' back into 'ids.*'
            if (Str::contains($paramName, '.0')) {
                $genericArrayKeyName = str_replace('.0', '.*', $paramName);

                // But only if the original rules used that key
                if (isset($rules[$genericArrayKeyName])) {
                    $paramName = $genericArrayKeyName;
                }
            }

            return [$paramName => $val];
        })->toArray();
    }

    /**
     * Appends the docblock written above an inline closure rule to the parameter
     * description, eg one reading "Must be a hexadecimal number." above
     * `function ($attribute, $value, $fail) { ... }` in a rules array.
     *
     * @param Closure|ClosureValidationRule $rule
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

    protected function processEnumValidationRule($rule, array &$parameterData, array $allParameters = []): void
    {
        $property = (new ReflectionClass($rule))->getProperty('type');
        $property->setAccessible(true);
        $enumClass = $property->getValue($rule);

        if (enum_exists($enumClass) && method_exists($enumClass, 'tryFrom')) {
            // Only a BackedEnum has ->value, and only a BackedEnum has tryFrom().
            // @phpstan-ignore property.notFound (a tryFrom() enum is a BackedEnum, which has ->value)
            $cases = array_map(fn ($case) => $case->value, $enumClass::cases());
            $parameterData['type'] = gettype($cases[0]);
            $parameterData['enumValues'] = $cases;
            // Seeded getFaker() keeps the picked example stable across doc runs
            // (Arr::random() uses random_int(), which examples.faker_seed can't fix).
            $parameterData['setter'] = fn () => $this->getFaker()->randomElement($cases);
        }
    }

    protected function processRuleObject($rule, array &$parameterData): void
    {
        if (method_exists($rule, 'invokable')) {
            // The validator wraps a ValidationRule object in an InvokableValidationRule; unwrap it.
            $rule = $rule->invokable();
        }

        // Users can define a custom "docs" method on a rule to give Scribe more info.
        if (method_exists($rule, 'docs')) {
            $customData = call_user_func_array([$rule, 'docs'], []) ?: [];

            if (isset($customData['description'])) {
                $parameterData['description'] .= ' ' . $customData['description'];
            }
            if (isset($customData['example'])) {
                $parameterData['setter'] = fn () => $customData['example'];
            } elseif (isset($customData['setter'])) {
                $parameterData['setter'] = $customData['setter'];
            }

            $parameterData = array_merge($parameterData, Arr::except($customData, [
                'description', 'example', 'setter',
            ]));
        }
    }

    /**
     * Applies one string rule to the parameter: its type, description and the
     * setter that generates its example.
     *
     * @param mixed $rule the lowercased rule name, eg `max`
     * @param mixed $ruleArguments the rule's arguments, eg `['3']` for `max:3`
     * @param array $allParameters all parameters, to tell whether a date rule's argument (eg `before:some_date`)
     *                             names another parameter
     */
    protected function processRule($rule, $ruleArguments, array &$parameterData, array $allParameters = []): bool
    {
        // Each rule appends to the description, with a leading space, rather than replacing it.
        try {
            switch ($rule) {
                case 'sometimes':
                    $parameterData['sometimes'] = true;

                    break;
                case 'required':
                    if (! $parameterData['sometimes']) {
                        $parameterData['required'] = true;
                    }

                    break;
                case 'accepted':
                    if (! $parameterData['sometimes']) {
                        $parameterData['required'] = true;
                    }
                    $parameterData['type'] = 'boolean';
                    $parameterData['description'] .= ' Must be accepted.';
                    $parameterData['setter'] = fn () => true;

                    break;
                case 'accepted_if':
                    $parameterData['type'] = 'boolean';
                    $parameterData['description'] .= " Must be accepted when <code>{$ruleArguments[0]}</code> is " . w::getListOfValuesAsFriendlyHtmlString(array_slice($ruleArguments, 1));
                    $parameterData['setter'] = fn () => true;

                    break;
                    // Type rules. Only `file` adds a description.
                case 'bool':
                case 'boolean':
                    $parameterData['setter'] = function () {
                        return $this->getFaker()->randomElement([true, false]);
                    };
                    $parameterData['type'] = 'boolean';

                    break;
                case 'string':
                    $parameterData['setter'] = function () use ($parameterData) {
                        return $this->generateDummyValue('string', ['name' => $parameterData['name']]);
                    };
                    $parameterData['type'] = 'string';

                    break;
                case 'int':
                case 'integer':
                    $parameterData['setter'] = function () {
                        return $this->generateDummyValue('integer');
                    };
                    $parameterData['type'] = 'integer';

                    break;
                case 'numeric':
                    $parameterData['setter'] = function () {
                        return $this->generateDummyValue('number');
                    };
                    $parameterData['type'] = 'number';

                    break;
                case 'array':
                    $parameterData['setter'] = function () {
                        return [$this->generateDummyValue('string')];
                    };
                    $parameterData['type'] = 'array'; // normaliseArrayAndObjectParameters() turns this into X[] or object

                    break;
                case 'file':
                    $parameterData['type'] = 'file';
                    $parameterData['description'] .= ' Must be a file.';
                    $parameterData['setter'] = function () {
                        return $this->generateDummyValue('file');
                    };

                    break;
                    // Special string types
                case 'alpha':
                    $parameterData['description'] .= ' Must contain only letters.';
                    $parameterData['setter'] = function () {
                        return $this->getFaker()->lexify('??????');
                    };

                    break;
                case 'alpha_dash':
                    $parameterData['description'] .= ' Must contain only letters, numbers, dashes and underscores.';
                    $parameterData['setter'] = function () {
                        return $this->getFaker()->lexify('???-???_?');
                    };

                    break;
                case 'alpha_num':
                    $parameterData['description'] .= ' Must contain only letters and numbers.';
                    $parameterData['setter'] = function () {
                        return $this->getFaker()->bothify('#?#???#');
                    };

                    break;
                case 'timezone':
                    // The validator's message gives no example of a time zone.
                    $parameterData['description'] .= ' Must be a valid time zone, such as <code>Africa/Accra</code>.';
                    $parameterData['setter'] = $this->getFakeFactoryByName('timezone');

                    break;
                case 'email':
                    $parameterData['description'] .= ' ' . $this->getDescription($rule);
                    $parameterData['setter'] = $this->getFakeFactoryByName('email');
                    $parameterData['type'] = 'string';

                    break;
                case 'url':
                    $parameterData['setter'] = $this->getFakeFactoryByName('url');
                    $parameterData['type'] = 'string';
                    $parameterData['description'] .= ' Must be a valid URL.';

                    break;
                case 'ip':
                    $parameterData['description'] .= ' ' . $this->getDescription($rule);
                    $parameterData['type'] = 'string';
                    $parameterData['setter'] = function () {
                        return $this->getFaker()->ipv4();
                    };

                    break;
                case 'json':
                    $parameterData['type'] = 'string';
                    $parameterData['description'] .= ' ' . $this->getDescription($rule);
                    $parameterData['setter'] = function () {
                        return json_encode([$this->getFaker()->word(), $this->getFaker()->word()]);
                    };

                    break;
                case 'date':
                    $parameterData['type'] = 'string';
                    $parameterData['description'] .= ' ' . $this->getDescription($rule);
                    // Carbon::now() honours Carbon::setTestNow(), so a consumer that
                    // freezes the clock while generating docs gets a stable example.
                    $parameterData['setter'] = fn () => Carbon::now()->format('Y-m-d\TH:i:s');

                    break;
                case 'date_format':
                    $parameterData['type'] = 'string';
                    // The validator's message ("must match the format Y-m-d") doesn't say the value is a date.
                    $parameterData['description'] .= " Must be a valid date in the format <code>{$ruleArguments[0]}</code>.";
                    $parameterData['setter'] = function () use ($ruleArguments) {
                        return Carbon::now()->format($ruleArguments[0]);
                    };

                    break;
                case 'after':
                case 'after_or_equal':
                    $parameterData['type'] = 'string';
                    $parameterData['description'] .= ' ' . $this->getDescription($rule, [':date' => "<code>{$ruleArguments[0]}</code>"]);
                    // When the argument names another field, the example range starts at now.
                    // The example is always Y-m-d; a date_format rule does not change it.
                    $startDate = array_key_exists($ruleArguments[0], $allParameters) ? Carbon::now() : $ruleArguments[0];
                    $parameterData['setter'] = fn () => $this->getFaker()->dateTimeBetween($startDate, Carbon::now()->addYears(100))->format('Y-m-d');

                    break;
                case 'before':
                case 'before_or_equal':
                    $parameterData['type'] = 'string';
                    // The argument is a date or another field's name; for a field, the example range ends at now.
                    $endDate = array_key_exists($ruleArguments[0], $allParameters) ? Carbon::now() : $ruleArguments[0];
                    $parameterData['description'] .= ' ' . $this->getDescription($rule, [':date' => "<code>{$ruleArguments[0]}</code>"]);
                    $parameterData['setter'] = fn () => $this->getFaker()->dateTimeBetween(Carbon::now()->subYears(30), $endDate)->format('Y-m-d');

                    break;
                case 'starts_with':
                    $parameterData['description'] .= ' Must start with one of ' . w::getListOfValuesAsFriendlyHtmlString($ruleArguments);
                    $parameterData['setter'] = fn () => $this->getFaker()->lexify("{$ruleArguments[0]}????");

                    break;
                case 'ends_with':
                    $parameterData['description'] .= ' Must end with one of ' . w::getListOfValuesAsFriendlyHtmlString($ruleArguments);
                    $parameterData['setter'] = fn () => $this->getFaker()->lexify("????{$ruleArguments[0]}");

                    break;
                case 'uuid':
                    $parameterData['description'] .= ' ' . $this->getDescription($rule) . ' ';
                    $parameterData['setter'] = $this->getFakeFactoryByName('uuid');

                    break;
                case 'regex':
                    $parameterData['description'] .= ' ' . $this->getDescription($rule, [':regex' => $ruleArguments[0]]);
                    $parameterData['setter'] = fn () => $this->getFaker()->regexify($ruleArguments[0]);

                    break;
                    // Special number types.
                case 'digits':
                    $parameterData['description'] .= ' ' . $this->getDescription($rule, [':digits' => $ruleArguments[0]]);
                    $parameterData['setter'] = fn () => $this->getFaker()->numerify(str_repeat('#', (int) $ruleArguments[0]));
                    $parameterData['type'] = 'string';

                    break;
                case 'digits_between':
                    $parameterData['description'] .= ' ' . $this->getDescription($rule, [':min' => $ruleArguments[0], ':max' => $ruleArguments[1]]);
                    $parameterData['setter'] = fn () => $this->getFaker()->numerify(str_repeat('#', rand((int) $ruleArguments[0], (int) $ruleArguments[1])));
                    $parameterData['type'] = 'string';

                    break;
                    // These rules can apply to numbers, strings, arrays or files
                case 'size':
                    $parameterData['description'] .= ' ' . $this->getDescription(
                        $rule,
                        [':size' => $ruleArguments[0]],
                        $this->getHypervelValidationBaseTypeMapping($parameterData['type'])
                    );
                    $parameterData['setter'] = $this->getDummyValueGenerator($parameterData['type'], ['size' => $ruleArguments[0]]);

                    break;
                case 'min':
                    $parameterData['description'] .= ' ' . $this->getDescription(
                        $rule,
                        [':min' => $ruleArguments[0]],
                        $this->getHypervelValidationBaseTypeMapping($parameterData['type'])
                    );
                    $parameterData['setter'] = $this->getDummyDataGeneratorBetween($parameterData['type'], (float) $ruleArguments[0], fieldName: $parameterData['name']);

                    break;
                case 'max':
                    $parameterData['description'] .= ' ' . $this->getDescription(
                        $rule,
                        [':max' => $ruleArguments[0]],
                        $this->getHypervelValidationBaseTypeMapping($parameterData['type'])
                    );
                    $max = min($ruleArguments[0], 25);
                    $parameterData['setter'] = $this->getDummyDataGeneratorBetween($parameterData['type'], 1, $max, $parameterData['name']);

                    break;
                case 'between':
                    $parameterData['description'] .= ' ' . $this->getDescription(
                        $rule,
                        [':min' => $ruleArguments[0], ':max' => $ruleArguments[1]],
                        $this->getHypervelValidationBaseTypeMapping($parameterData['type'])
                    );
                    // Avoid exponentially complex operations by using the minimum length
                    $parameterData['setter'] = $this->getDummyDataGeneratorBetween($parameterData['type'], (float) $ruleArguments[0], (float) $ruleArguments[0] + 1, $parameterData['name']);

                    break;
                    // Special file types.
                case 'image':
                    $parameterData['type'] = 'file';
                    $parameterData['description'] .= ' ' . $this->getDescription($rule) . ' ';
                    $parameterData['setter'] = function () {
                        // The generated example file is a .jpg, which suits the image rule.
                        return $this->generateDummyValue('file');
                    };

                    break;
                    // Other rules.
                case 'in':
                    // Cast numeric values in enumValues, but keep the type other rules set
                    // (eg 'string'), or the default 'string'.
                    $castedValues = $ruleArguments;
                    $allNumeric = count($ruleArguments) > 0 && array_reduce(
                        $ruleArguments,
                        fn ($carry, $val) => $carry && is_numeric($val),
                        true
                    );

                    if ($allNumeric) {
                        // Check if all are integers (no decimal points)
                        $allIntegers = array_reduce(
                            $ruleArguments,
                            fn ($carry, $val) => $carry && ! Str::contains($val, '.'),
                            true
                        );

                        $castedValues = $allIntegers
                            ? array_map(fn ($v) => (int) $v, $ruleArguments)
                            : array_map(fn ($v) => (float) $v, $ruleArguments);
                    }

                    $parameterData['enumValues'] = $castedValues;
                    $parameterData['setter'] = function () use ($castedValues) {
                        return $this->getFaker()->randomElement($castedValues);
                    };

                    break;
                    // These rules only add a description. Generating valid examples is too complex.
                case 'not_in':
                    $parameterData['description'] .= ' Must not be one of ' . w::getListOfValuesAsFriendlyHtmlString($ruleArguments) . ' ';

                    break;
                case 'required_if':
                    $parameterData['description'] .= sprintf(
                        " This field is required when <code>{$ruleArguments[0]}</code> is %s. ",
                        w::getListOfValuesAsFriendlyHtmlString(array_slice($ruleArguments, 1))
                    );

                    break;
                case 'required_unless':
                    $parameterData['description'] .= sprintf(
                        " This field is required unless <code>{$ruleArguments[0]}</code> is in %s. ",
                        w::getListOfValuesAsFriendlyHtmlString(array_slice($ruleArguments, 1))
                    );

                    break;
                case 'required_with':
                    $parameterData['description'] .= sprintf(
                        ' This field is required when %s is present. ',
                        w::getListOfValuesAsFriendlyHtmlString($ruleArguments)
                    );

                    break;
                case 'required_without':
                    $parameterData['description'] .= sprintf(
                        ' This field is required when %s is not present. ',
                        w::getListOfValuesAsFriendlyHtmlString($ruleArguments)
                    );

                    break;
                case 'required_with_all':
                    $parameterData['description'] .= sprintf(
                        ' This field is required when %s are present. ',
                        w::getListOfValuesAsFriendlyHtmlString($ruleArguments, 'and')
                    );

                    break;
                case 'required_without_all':
                    $parameterData['description'] .= sprintf(
                        ' This field is required when none of %s are present. ',
                        w::getListOfValuesAsFriendlyHtmlString($ruleArguments, 'and')
                    );

                    break;
                case 'same':
                    $parameterData['description'] .= " The value and <code>{$ruleArguments[0]}</code> must match.";

                    break;
                case 'different':
                    $parameterData['description'] .= " The value and <code>{$ruleArguments[0]}</code> must be different.";

                    break;
                case 'nullable':
                    $parameterData['nullable'] = true;

                    break;
                case 'exists':
                    $parameterData['description'] .= ' Must match an existing stored value.';

                    break;
                default:
                    // Other rules add nothing.
                    break;
            }
        } catch (Throwable $e) {
            throw CouldntProcessValidationRule::forParam($parameterData['name'], $rule, $e);
        }

        $parameterData['description'] = mb_trim($parameterData['description']);

        return true;
    }

    /**
     * Splits a string rule of the form {rule}:{arguments} into its lowercased name
     * and comma-separated arguments, eg "in:1,2" becomes ["in", ["1", "2"]].
     *
     * @param Rule|string $rule
     */
    protected function parseStringRuleIntoRuleAndArguments($rule): array
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

    protected function getParameterExample(array $parameterData)
    {
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
            if ($parameterData['example'] === 'No-example' && ! $parameterData['required']) {
                return null;
            }

            // Cast again, since the validator may have turned the value into a string.
            return $this->castToType($parameterData['example'], $parameterData['type']);
        }

        return $parameterData['example'] === self::$MISSING_VALUE ? null : $parameterData['example'];
    }

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

    protected function getDescription(string $rule, array $arguments = [], $baseType = 'string'): string
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

    protected function getMissingCustomDataMessage($parameterName)
    {
        return '';
    }

    protected function shouldCastUserExample()
    {
        return false;
    }

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

    private function examplePresent(array $parameterData)
    {
        return isset($parameterData['example']) && $parameterData['example'] !== self::$MISSING_VALUE;
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
