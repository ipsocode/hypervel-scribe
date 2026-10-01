<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit\Extracting;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Scribe\Extracting\ParamHelpers;
use Ipsocode\Scribe\Tests\Unit\TestCase;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class ParamHelpersTest extends TestCase
{
    /**
     * The trait expects a `$config` (DocumentationConfig) and only exposes its
     * methods as protected; this thin subject surfaces them for testing.
     */
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new class {
            use ParamHelpers;

            public DocumentationConfig $config;

            public function __construct()
            {
                $this->config = new DocumentationConfig(['examples' => ['faker_seed' => 1234]]);
            }

            public function castValue(mixed $value, string $type): mixed
            {
                return $this->castToType($value, $type);
            }

            public function parseExample(string $description, string $type): array
            {
                return $this->parseExampleFromParamDescription($description, $type);
            }

            public function excludeExample(string $description): bool
            {
                return $this->shouldExcludeExample($description);
            }

            public function dummy(string $type, array $hints = []): mixed
            {
                return $this->generateDummyValue($type, $hints);
            }
        };
    }

    #[UnitTest]
    #[Test]
    #[DataProvider('typeNames')]
    public function normalizesTypeNames(?string $input, string $expected): void
    {
        $this->assertSame($expected, $this->subject::normalizeTypeName($input));
    }

    public static function typeNames(): array
    {
        return [
            'null defaults to string' => [null, 'string'],
            'int' => ['int', 'integer'],
            'bool' => ['bool', 'boolean'],
            'float' => ['float', 'number'],
            'double' => ['double', 'number'],
            'array suffix preserved' => ['int[]', 'integer[]'],
            'unknown passes through' => ['MyType', 'MyType'],
        ];
    }

    #[UnitTest]
    #[Test]
    public function normalizesArrayValueTypesFromASampleValue(): void
    {
        $this->assertSame('integer[]', $this->subject::normalizeTypeName('array', [1, 2, 3]));
        $this->assertSame('object', $this->subject::normalizeTypeName('array', ['key' => 'value']));
    }

    #[UnitTest]
    #[Test]
    public function castsScalarValuesToTheirDeclaredType(): void
    {
        $this->assertSame(5, $this->subject->castValue('5', 'integer'));
        $this->assertSame(1.5, $this->subject->castValue('1.5', 'number'));
        $this->assertTrue($this->subject->castValue('true', 'boolean'));
        $this->assertNull($this->subject->castValue(null, 'integer'));
    }

    #[UnitTest]
    #[Test]
    public function castsTheStringFalseToARealBoolean(): void
    {
        // PHP treats the non-empty string 'false' as true, so this needs special handling.
        $this->assertFalse($this->subject->castValue('false', 'boolean'));
    }

    #[UnitTest]
    #[Test]
    public function castsArrayAndObjectTypes(): void
    {
        $this->assertSame([1, 2], $this->subject->castValue([1, 2], 'integer[]'));
        $this->assertSame(['a' => 1], $this->subject->castValue('{"a":1}', 'object'));
    }

    #[UnitTest]
    #[Test]
    public function parsesAnInlineExampleFromADescription(): void
    {
        [$description, $example, $enumValues, $exampleWasSpecified]
            = $this->subject->parseExample('The user id. Example: 3', 'integer');

        $this->assertSame('The user id.', $description);
        $this->assertSame(3, $example);
        $this->assertSame([], $enumValues);
        $this->assertTrue($exampleWasSpecified);
    }

    #[UnitTest]
    #[Test]
    public function parsesAnExplicitNullExample(): void
    {
        [, $example, , $exampleWasSpecified] = $this->subject->parseExample('A value. Example: null', 'string');

        $this->assertNull($example);
        $this->assertTrue($exampleWasSpecified);
    }

    #[UnitTest]
    #[Test]
    public function parsesEnumValuesFromADescription(): void
    {
        [$description, , $enumValues] = $this->subject->parseExample('The status. Enum: active, inactive', 'string');

        $this->assertSame('The status.', $description);
        $this->assertSame(['active', 'inactive'], $enumValues);
    }

    #[UnitTest]
    #[Test]
    public function detectsTheNoExampleMarker(): void
    {
        $this->assertTrue($this->subject->excludeExample('The id. No-example'));
        $this->assertFalse($this->subject->excludeExample('The id.'));
    }

    #[UnitTest]
    #[Test]
    public function castsAGenericArrayAsAListOfStrings(): void
    {
        // `array` is what a validation rule gives, and it is read as `string[]`
        // — so a JSON string is decoded rather than left as a string.
        $this->assertSame([1, 2], $this->subject->castValue('[1,2]', 'array'));
    }

    #[UnitTest]
    #[Test]
    #[DataProvider('namedParameters')]
    public function generatesAnExampleThatSuitsTheParametersName(string $name, string $pattern): void
    {
        // A field called `email` documented with a random word helps nobody,
        // so the name is consulted before the type.
        $this->assertMatchesRegularExpression(
            $pattern,
            (string) $this->subject->dummy('string', ['name' => $name]),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function namedParameters(): array
    {
        return [
            'email' => ['email', '/@/'],
            'email suffix' => ['contact_email', '/@/'],
            'password' => ['password', '/.{6,}/'],
            'pwd' => ['pwd', '/.{6,}/'],
            'url' => ['website_url', '#^https?://#'],
            'description' => ['description', '/\w+ /'],
            'uuid' => ['uuid', '/^[0-9a-f-]{36}$/'],
            'locale' => ['locale', '/^[a-z]{2}/i'],
            'timezone' => ['timezone', '#/#'],
            'dotted name uses its last segment' => ['user.email', '/@/'],
        ];
    }

    #[UnitTest]
    #[Test]
    public function anUnrecognisedNameFallsBackToTheType(): void
    {
        $this->assertIsInt($this->subject->dummy('integer', ['name' => 'quantity']));
    }

    #[UnitTest]
    #[Test]
    public function aFileParameterIgnoresItsName(): void
    {
        // `url` would otherwise win over the type and document a file upload as
        // a web address, rather than as an uploaded file.
        $this->assertIsObject($this->subject->dummy('file', ['name' => 'url']));
    }

    #[UnitTest]
    #[Test]
    public function enumValuesWinOverEverythingElse(): void
    {
        $this->assertContains($this->subject->dummy('string', ['enumValues' => ['a', 'b']]), ['a', 'b']);
    }

    #[UnitTest]
    #[Test]
    public function aListTypeGeneratesAOneItemListOfItsBaseType(): void
    {
        $example = $this->subject->dummy('integer[]');

        $this->assertCount(1, $example);
        $this->assertIsInt($example[0]);
    }

    #[UnitTest]
    #[Test]
    public function aSizedListStillGeneratesOneItem(): void
    {
        // The size constrains the item, not the list: a `size:3` on `ids.*`
        // means each id is 3, not that there are three of them.
        $this->assertCount(1, $this->subject->dummy('string[]', ['size' => 3]));
    }

    #[UnitTest]
    #[Test]
    public function anExactSizeIsUsedAsTheNumericExample(): void
    {
        // `digits:5` and `size:5` mean the value itself, so a generated example
        // that ignored them would fail the rule it came from.
        $this->assertSame(5, $this->subject->dummy('integer', ['size' => 5]));
        $this->assertSame(5, $this->subject->dummy('number', ['size' => 5]));
    }

    #[UnitTest]
    #[Test]
    public function aMinAndMaxOverrideTheSize(): void
    {
        $this->assertGreaterThanOrEqual(10, $this->subject->dummy('integer', ['size' => 5, 'min' => 10, 'max' => 20]));
    }
}
