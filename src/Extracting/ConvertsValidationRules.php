<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting;

use Hypervel\Support\Carbon;
use Hypervel\Support\Str;
use Ipsocode\Scribe\Exceptions\CouldntProcessValidationRule;
use Ipsocode\Scribe\Tools\WritingUtils as w;
use Throwable;

/**
 * Turns one string validation rule into its effect on a parameter: type,
 * description and example setter. Used by ParsesValidationRules.
 */
trait ConvertsValidationRules
{
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
}
