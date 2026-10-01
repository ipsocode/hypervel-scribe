<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Extracting;

use Closure;
use Hypervel\Contracts\Validation\ValidationRule;
use Hypervel\Support\Facades\Lang;
use Hypervel\Support\Facades\Validator;
use Hypervel\Validation\Rule;
use Ipsocode\Scribe\Exceptions\CouldntProcessValidationRule;
use Ipsocode\Scribe\Exceptions\ProblemParsingValidationRules;
use Ipsocode\Scribe\Extracting\ParsesValidationRules;
use Ipsocode\Scribe\Tests\TestCase;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Workbench\App\Enums\PostStatus;

/**
 * The rule parser: validation rules in, documented parameters (types,
 * descriptions, examples) out. A Feature test because the descriptions come
 * from the framework's `validation.*` translations and the examples from a
 * seeded faker resolved through the container.
 */
class ParsesValidationRulesTest extends TestCase
{
    private object $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new class {
            use ParsesValidationRules;

            public DocumentationConfig $config;

            public function __construct()
            {
                // A fixed seed keeps generated examples stable, which is what
                // makes asserting on them meaningful at all.
                $this->config = new DocumentationConfig(['examples' => ['faker_seed' => 1234]]);
            }

            /**
             * The two protected extension points the tests below reach directly,
             * because no ruleset can route a call to them: Hypervel's validator
             * wraps every closure rule before Scribe sees it, and only a fixed
             * list of rule names asks {@see getDescription()} for a base type.
             */
            public function describeRule(string $rule, array $arguments = [], string $baseType = 'string'): string
            {
                return $this->getDescription($rule, $arguments, $baseType);
            }

            public function readClosureRule($rule, array &$parameterData): void
            {
                $this->processClosureRule($rule, $parameterData);
            }
        };
    }

    /**
     * Run the full pipeline the real strategies run: parse the rules, then
     * normalise array/object shapes and resolve the generated examples.
     *
     * @return array<string, array<string, mixed>>
     */
    private function parseAll(array $rulesByParameter, array $custom = []): array
    {
        return $this->parser->normaliseArrayAndObjectParameters(
            $this->parser->getParametersFromValidationRules($rulesByParameter, $custom),
        );
    }

    /**
     * @return array<string, mixed> the single parsed parameter
     */
    private function parse(array|string $rules, array $custom = []): array
    {
        return $this->parseAll(['field' => $rules], $custom)['field'];
    }

    #[Test]
    #[DataProvider('typeRules')]
    public function mapsATypeRuleOntoADocumentedType(string $rule, string $expectedType): void
    {
        $this->assertSame($expectedType, $this->parse([$rule])['type']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function typeRules(): array
    {
        return [
            'bool' => ['bool', 'boolean'],
            'boolean' => ['boolean', 'boolean'],
            'string' => ['string', 'string'],
            'int' => ['int', 'integer'],
            'integer' => ['integer', 'integer'],
            'numeric' => ['numeric', 'number'],
            'file' => ['file', 'file'],
            'image' => ['image', 'file'],
            'email' => ['email', 'string'],
            'url' => ['url', 'string'],
            'ip' => ['ip', 'string'],
            'json' => ['json', 'string'],
            'date' => ['date', 'string'],
            'digits' => ['digits:4', 'string'],
        ];
    }

    #[Test]
    #[DataProvider('describedRules')]
    public function describesARuleForAHuman(array|string $rules, string $expectedFragment): void
    {
        $this->assertStringContainsString($expectedFragment, $this->parse($rules)['description']);
    }

    /**
     * @return array<string, array{array|string, string}>
     */
    public static function describedRules(): array
    {
        return [
            'accepted' => [['accepted'], 'Must be accepted.'],
            'accepted_if' => [['accepted_if:other,yes'], 'Must be accepted when <code>other</code> is <code>yes</code>'],
            'file' => [['file'], 'Must be a file.'],
            'alpha' => [['alpha'], 'Must contain only letters.'],
            'alpha_dash' => [['alpha_dash'], 'Must contain only letters, numbers, dashes and underscores.'],
            'alpha_num' => [['alpha_num'], 'Must contain only letters and numbers.'],
            'timezone' => [['timezone'], 'Must be a valid time zone'],
            'url' => [['url'], 'Must be a valid URL.'],
            'date_format' => [['date_format:Y-m-d'], 'Must be a valid date in the format <code>Y-m-d</code>.'],
            'starts_with' => [['starts_with:pre,post'], 'Must start with one of <code>pre</code> or <code>post</code>'],
            'ends_with' => [['ends_with:_x'], 'Must end with one of <code>_x</code>'],
            'regex' => [['regex:/^\d+$/'], 'Must match the regex /^\d+$/.'],
            'not_in' => [['not_in:a,b'], 'Must not be one of <code>a</code> or <code>b</code>'],
            'required_if' => [['required_if:other,yes'], 'This field is required when <code>other</code> is <code>yes</code>.'],
            'required_unless' => [['required_unless:other,yes'], 'This field is required unless <code>other</code> is in <code>yes</code>.'],
            'required_with' => [['required_with:a,b'], 'This field is required when <code>a</code> or <code>b</code> is present.'],
            'required_without' => [['required_without:a'], 'This field is required when <code>a</code> is not present.'],
            'required_with_all' => [['required_with_all:a,b'], 'This field is required when <code>a</code> and <code>b</code> are present.'],
            'required_without_all' => [['required_without_all:a,b'], 'This field is required when none of <code>a</code> and <code>b</code> are present.'],
            'same' => [['same:other'], 'The value and <code>other</code> must match.'],
            'different' => [['different:other'], 'The value and <code>other</code> must be different.'],
            'exists' => [['exists:posts,id'], 'Must match an existing stored value.'],
        ];
    }

    #[Test]
    public function requiredAndSometimesAndNullableSetTheParameterFlags(): void
    {
        $required = $this->parse(['required', 'string']);
        $this->assertTrue($required['required']);
        $this->assertFalse($required['nullable']);

        // `sometimes` means the field may be absent entirely, so a later
        // `required` must not override it.
        $sometimes = $this->parse(['sometimes', 'required', 'string']);
        $this->assertFalse($sometimes['required']);

        $this->assertTrue($this->parse(['nullable', 'string'])['nullable']);
    }

    #[Test]
    public function acceptedMarksTheFieldRequiredAndSetsATrueExample(): void
    {
        $param = $this->parse(['accepted']);

        $this->assertTrue($param['required']);
        $this->assertTrue($param['example']);
    }

    #[Test]
    public function inBecomesAListOfEnumValues(): void
    {
        $param = $this->parse(['in:draft,published']);

        $this->assertSame(['draft', 'published'], $param['enumValues']);
        $this->assertContains($param['example'], ['draft', 'published']);
    }

    #[Test]
    public function numericInValuesAreCastToIntegers(): void
    {
        // Left as strings, the generated OpenAPI enum would be ["1","2"] for a
        // parameter documented as an integer.
        $this->assertSame([1, 2, 3], $this->parse(['integer', 'in:1,2,3'])['enumValues']);
    }

    #[Test]
    public function decimalInValuesAreCastToFloats(): void
    {
        $this->assertSame([1.5, 2.5], $this->parse(['numeric', 'in:1.5,2.5'])['enumValues']);
    }

    #[Test]
    public function mixedInValuesAreLeftAsStrings(): void
    {
        $this->assertSame(['1', 'two'], $this->parse(['in:1,two'])['enumValues']);
    }

    #[Test]
    public function minMaxAndBetweenDescribeThemselvesPerBaseType(): void
    {
        // The same rule reads differently for a string, a number and a file,
        // and the base type is what picks the message.
        $this->assertStringContainsString('characters', $this->parse(['string', 'min:5'])['description']);
        $this->assertStringContainsString('greater than', $this->parse(['integer', 'max:10'])['description']);
        $this->assertStringContainsString('between', $this->parse(['string', 'between:3,5'])['description']);
        $this->assertStringContainsString('kilobyte', $this->parse(['file', 'max:1024'])['description']);
    }

    #[Test]
    public function sizeAndDigitsRulesGenerateAMatchingExample(): void
    {
        $this->assertSame(4, mb_strlen((string) $this->parse(['digits:4'])['example']));

        $digitsBetween = (string) $this->parse(['digits_between:2,4'])['example'];
        $this->assertGreaterThanOrEqual(2, mb_strlen($digitsBetween));
        $this->assertLessThanOrEqual(4, mb_strlen($digitsBetween));

        $this->assertSame(6, mb_strlen($this->parse(['string', 'size:6'])['example']));
    }

    #[Test]
    public function dateRulesGenerateAParseableExample(): void
    {
        $this->assertNotFalse(strtotime($this->parse(['date'])['example']));
        $this->assertNotFalse(strtotime($this->parse(['date_format:Y-m-d'])['example']));
        $this->assertNotFalse(strtotime($this->parse(['after:2020-01-01'])['example']));
        $this->assertNotFalse(strtotime($this->parse(['before:2030-01-01'])['example']));
        $this->assertNotFalse(strtotime($this->parse(['after_or_equal:2020-01-01'])['example']));
        $this->assertNotFalse(strtotime($this->parse(['before_or_equal:2030-01-01'])['example']));
    }

    #[Test]
    public function aDateRuleThatPointsAtAnotherFieldStillGeneratesAnExample(): void
    {
        // `after:start_date` names a sibling field rather than a date, and
        // passing that to the faker as a date string would blow up.
        $parsed = $this->parseAll([
            'start_date' => ['date'],
            'end_date' => ['after:start_date'],
        ]);

        $this->assertNotFalse(strtotime($parsed['end_date']['example']));
    }

    #[Test]
    public function generatedExamplesSatisfyTheirOwnStringRules(): void
    {
        $this->assertMatchesRegularExpression('/^[a-zA-Z]+$/', $this->parse(['alpha'])['example']);
        $this->assertMatchesRegularExpression('/^\d+$/', $this->parse(['regex:/^\d+$/'])['example']);
        $this->assertStringStartsWith('pre', $this->parse(['starts_with:pre'])['example']);
        $this->assertStringEndsWith('_x', $this->parse(['ends_with:_x'])['example']);
        $this->assertStringContainsString('@', $this->parse(['email'])['example']);
        $this->assertIsString($this->parse(['uuid'])['example']);
        $this->assertJson($this->parse(['json'])['example']);
    }

    #[Test]
    public function aUserSuppliedDescriptionComesFirstAndIsPunctuated(): void
    {
        $description = $this->parse(['email'], ['field' => ['description' => 'The email']])['description'];

        $this->assertStringStartsWith('The email.', $description);
    }

    #[Test]
    public function aUserSuppliedExampleWinsOverTheGeneratedOne(): void
    {
        $this->assertSame(
            'ada@example.com',
            $this->parse(['email'], ['field' => ['example' => 'ada@example.com']])['example'],
        );
    }

    #[Test]
    public function aClosureRuleIsToleratedWithoutAnExample(): void
    {
        // Scribe cannot know what a closure accepts, so it must not guess — and
        // must not crash either.
        $param = $this->parse(['string', fn ($attribute, $value, $fail) => null]);

        $this->assertSame('string', $param['type']);
    }

    #[Test]
    public function anEnumRuleObjectBecomesTheEnumValues(): void
    {
        $param = $this->parse([Rule::enum(PostStatus::class)]);

        $this->assertSame(['draft', 'published'], $param['enumValues']);
        $this->assertContains($param['example'], ['draft', 'published']);
    }

    #[Test]
    public function aRuleGivenAsAPipeDelimitedStringIsSplit(): void
    {
        // `'field' => 'required|email'` is the common spelling, and has to
        // behave identically to the array form.
        $param = $this->parse('required|email');

        $this->assertTrue($param['required']);
        $this->assertSame('string', $param['type']);
    }

    #[Test]
    public function anUnsupportedRuleIsIgnoredRatherThanFatal(): void
    {
        $param = $this->parse(['string', 'some_custom_rule:with,args']);

        $this->assertSame('string', $param['type']);
    }

    #[Test]
    public function aRuleThatThrowsIsReportedAgainstItsParameter(): void
    {
        $this->expectException(CouldntProcessValidationRule::class);

        // `date_format` reads $ruleArguments[0], which is absent here.
        $this->parse(['date_format']);
    }

    #[Test]
    public function arrayParametersAreNormalisedIntoTypedSubfields(): void
    {
        $parsed = $this->parseAll([
            'tags' => ['array'],
            'tags.*' => ['string'],
        ]);

        $this->assertSame('string[]', $parsed['tags']['type']);
    }

    #[Test]
    public function objectParametersKeepTheirSubfields(): void
    {
        $parsed = $this->parseAll([
            'address' => ['array'],
            'address.city' => ['string'],
        ]);

        $this->assertSame('object', $parsed['address']['type']);
        $this->assertArrayHasKey('address.city', $parsed);
    }

    #[Test]
    public function aMissingParentFieldIsSynthesised(): void
    {
        // An application may validate only `address.city`; the docs still need
        // an `address` object to hang it off.
        $parsed = $this->parseAll(['address.city' => ['string']]);

        $this->assertArrayHasKey('address', $parsed);
        $this->assertSame('object', $parsed['address']['type']);
    }

    #[Test]
    public function aMissingParentOfAListSubfieldIsSynthesisedAsAnObjectList(): void
    {
        // `cars.*.name` says nothing about `cars` itself, but the `.*` does:
        // it is a list, and each item is an object with a `name`.
        $parsed = $this->parseAll(['cars.*.name' => ['string']]);

        $this->assertSame('object[]', $parsed['cars']['type']);
        $this->assertSame([[]], $parsed['cars']['example']);
        $this->assertArrayHasKey('cars[].name', $parsed);
    }

    #[Test]
    public function aParentDeclaredAlongsideItsListSubfieldIsNotOverwritten(): void
    {
        // `cars` is already there, so the synthesiser must leave it — and with
        // it the `array` rule's own (empty) example, not a fabricated `[[]]`.
        $parsed = $this->parseAll([
            'cars' => ['array'],
            'cars.*.name' => ['string'],
        ]);

        $this->assertSame('object[]', $parsed['cars']['type']);
        $this->assertNull($parsed['cars']['example']);
    }

    #[Test]
    public function aParentDeclaredAfterItsSubfieldIsNotSynthesisedTwice(): void
    {
        // The synthesised `address` comes first; reaching the user's own
        // `address` afterwards must not replace it and lose the subfield.
        $parsed = $this->parseAll([
            'address.city' => ['string'],
            'address' => ['array'],
        ]);

        $this->assertSame('object', $parsed['address']['type']);
        $this->assertArrayHasKey('address.city', $parsed);
    }

    #[Test]
    public function anArrayOfObjectsIsTypedFromItsGrandchildren(): void
    {
        // `users.*.thing` means each `users` item is an object, so `users` is
        // `object[]` — not `string[]` off the type of the leaf.
        $parsed = $this->parseAll([
            'users' => ['array'],
            'users.*.thing' => ['string'],
        ]);

        $this->assertSame('object[]', $parsed['users']['type']);
        $this->assertArrayHasKey('users[].thing', $parsed);
    }

    #[Test]
    public function aGenericArrayGeneratesAOneItemExample(): void
    {
        // Declaring the subfield first leaves the `array` rule's own setter in
        // place, and it is what has to produce a sensible list example.
        $parsed = $this->parseAll([
            'tags.*' => ['string'],
            'tags' => ['array'],
        ]);

        $this->assertSame('string[]', $parsed['tags']['type']);
        $this->assertCount(1, $parsed['tags']['example']);
        $this->assertIsString($parsed['tags']['example'][0]);
    }

    #[Test]
    public function anExampleOnAListSubfieldIsWrappedIntoAList(): void
    {
        // The user described one item; the documented field is the whole list.
        $parsed = $this->parseAll(
            ['tags' => ['array'], 'tags.*' => ['string']],
            ['tags.*' => ['example' => 'featured']],
        );

        $this->assertSame(['featured'], $parsed['tags']['example']);
    }

    #[Test]
    public function anObjectSubfieldTakesItsExampleFromItsParent(): void
    {
        // Setting `data` => ['example' => ['title' => ...]] is the natural way
        // to describe a whole object, and `data.title` should follow from it
        // rather than getting an unrelated generated string.
        $parsed = $this->parseAll(
            ['data' => ['array'], 'data.title' => ['string']],
            ['data' => ['example' => ['title' => 'A title']]],
        );

        $this->assertSame('A title', $parsed['data.title']['example']);
    }

    #[Test]
    public function theNoExampleMarkerSuppressesAnOptionalFieldsExample(): void
    {
        $this->assertNull($this->parse(['string'], ['field' => ['example' => 'No-example']])['example']);
    }

    #[Test]
    public function aDocumentedClosureRuleContributesItsDescription(): void
    {
        // A closure can't be introspected, but its docblock can — and that is
        // the only way a custom inline rule gets documented at all.
        /** Must be a hexadecimal number. */
        $isHex = function ($attribute, $value, $fail) {};

        $this->assertSame('Must be a hexadecimal number.', $this->parse(['string', $isHex])['description']);
    }

    #[Test]
    public function aClosureRuleIsReadWhetherOrNotTheValidatorWrappedIt(): void
    {
        // Hypervel's validator wraps every closure rule in a
        // ClosureValidationRule, so the bare-closure branch is only reachable
        // from a caller assembling parameter data itself.
        /** Must be a hexadecimal number. */
        $isHex = function ($attribute, $value, $fail) {};

        $parameterData = ['description' => 'A colour.'];
        $this->parser->readClosureRule($isHex, $parameterData);

        // The pipeline trims and full-stops descriptions afterwards, so what
        // lands here is the raw appended text.
        $this->assertStringStartsWith('A colour.', $parameterData['description']);
        $this->assertStringContainsString('Must be a hexadecimal number.', $parameterData['description']);
    }

    #[Test]
    public function anUndocumentedClosureRuleContributesNothing(): void
    {
        $parameterData = ['description' => 'A colour.'];
        $this->parser->readClosureRule(fn ($attribute, $value, $fail) => null, $parameterData);

        $this->assertSame('A colour.', $parameterData['description']);
    }

    #[Test]
    public function aRuleObjectCanDocumentItself(): void
    {
        // The validator wraps a ValidationRule in an InvokableValidationRule,
        // so Scribe has to unwrap it before looking for `docs()`.
        $param = $this->parse([new SelfDocumentingRule]);

        $this->assertStringContainsString('Must be a hex colour.', $param['description']);
        $this->assertSame('#ff9900', $param['example']);
        $this->assertSame(['#ff9900', '#0099ff'], $param['enumValues']);
    }

    #[Test]
    public function aRuleObjectCanSupplyASetterInsteadOfAnExample(): void
    {
        $this->assertSame('generated', $this->parse([new SetterRule])['example']);
    }

    #[Test]
    public function aTypeFromARuleObjectDrivesLaterTypeDependentRules(): void
    {
        // `min` reads differently per base type, and a documented `string[]`
        // has to map onto Hypervel's `array` messages rather than `string`.
        $param = $this->parse([new ListRule, 'min:2']);

        $this->assertSame('string[]', $param['type']);
        $this->assertStringContainsString('items', $param['description']);
    }

    #[Test]
    public function aRuleThatBlowsUpIsReportedAgainstItsParameter(): void
    {
        // Anything that isn't already a ScribeException gets wrapped, so the
        // user is told which parameter to look at rather than getting a bare
        // stack.
        $this->expectException(ProblemParsingValidationRules::class);
        $this->expectExceptionMessage('field');

        $this->parse([new BrokenRule]);
    }

    #[Test]
    public function aRuleDescribedOnlyPerBaseTypeIsLookedUpThatWay(): void
    {
        // Some translation engines don't return the array under
        // `validation.<rule>`; the base type has to be appended to find a
        // message at all.
        Lang::addLines(['validation.length_limit.string' => 'Must be a sensible length.'], 'en');

        $this->assertSame('Must be a sensible length.', $this->parser->describeRule('length_limit', [], 'string'));
    }

    #[Test]
    public function aRuleWithNoTranslationAtAllKeepsItsKey(): void
    {
        $this->assertSame('validation.no_such_rule', $this->parser->describeRule('no_such_rule', [], 'string'));
    }

    #[Test]
    public function aTranslationEngineThatReportsAMissIsRetriedWithTheBaseType(): void
    {
        // Hypervel's own translator hands back the whole `validation.min` array
        // and the base type picks a message out of it. An engine that instead
        // reports a miss for the array key has to be asked for
        // `validation.min.<base type>` directly.
        $this->app->instance('translator', new FlatKeyTranslator([
            'validation.length_limit.string' => 'Must be a sensible length.',
        ]));

        $this->assertSame('Must be a sensible length.', $this->parser->describeRule('length_limit', [], 'string'));
    }

    #[Test]
    public function laravelsAsteriskPlaceholderIsMappedBackToAWildcard(): void
    {
        // The validator spells an escaped `*` in a rule key as
        // `__asterisk__<random>`; a ruleset arriving with such keys still has
        // to come back out as `foo.*`.
        Validator::swap(new PlaceholderKeyValidatorFactory([
            'ids.__asterisk__dkjiu78gujjhb' => ['integer'],
        ]));

        $parsed = $this->parser->getParametersFromValidationRules(['ids.*' => 'integer']);

        $this->assertArrayHasKey('ids.*', $parsed);
        $this->assertSame('integer', $parsed['ids.*']['type']);
    }

    #[Test]
    public function aGenericArrayRuleCarriesAOneItemExample(): void
    {
        // `normaliseArrayAndObjectParameters()` re-types `array` into `x[]` or
        // `object` off the subfields, and the concrete subfield's own example
        // wins once it does. Until then the `array` rule stands on its own, and
        // a caller reading the raw parameters gets a usable list example.
        $parsed = $this->parser->getParametersFromValidationRules(['tags' => ['array']]);

        $this->assertSame('array', $parsed['tags']['type']);
        $example = $parsed['tags']['setter']();
        $this->assertCount(1, $example);
        $this->assertIsString($example[0]);
    }
}

/**
 * A translation engine that only ever matches whole dotted keys, so
 * `validation.length_limit` misses where `validation.length_limit.string` hits.
 */
class FlatKeyTranslator
{
    public function __construct(private array $lines)
    {
    }

    public function get(string $key, array $replace = [], ?string $locale = null, bool $fallback = true): array|string
    {
        return $this->lines[$key] ?? $key;
    }
}

/**
 * Stands in for the `Validator` facade so `normaliseRules()` sees rule keys
 * carrying `__asterisk__<random>` placeholders.
 */
class PlaceholderKeyValidatorFactory
{
    public function __construct(private array $rules)
    {
    }

    public function make(array $data, array $rules): object
    {
        return new class($this->rules) {
            public function __construct(private array $rules)
            {
            }

            public function getRules(): array
            {
                return $this->rules;
            }
        };
    }
}

/**
 * A rule that tells Scribe about itself through the optional `docs()` method.
 */
class SelfDocumentingRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
    }

    public function docs(): array
    {
        return [
            'description' => 'Must be a hex colour.',
            'example' => '#ff9900',
            'enumValues' => ['#ff9900', '#0099ff'],
        ];
    }
}

/**
 * `setter` is the lazy spelling of `example`, for a value that should not be
 * computed until the docs are generated.
 */
class SetterRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
    }

    public function docs(): array
    {
        return ['setter' => fn () => 'generated'];
    }
}

class ListRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
    }

    public function docs(): array
    {
        return ['type' => 'string[]'];
    }
}

class BrokenRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
    }

    public function docs(): array
    {
        throw new RuntimeException('This rule cannot describe itself.');
    }
}
