<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit\Tools;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Scribe\Scribe;
use Ipsocode\Scribe\Tests\Unit\TestCase;
use Ipsocode\Scribe\Tools\WritingUtils;
use PHPUnit\Framework\Attributes\Test;

class WritingUtilsTest extends TestCase
{
    #[UnitTest]
    #[Test]
    public function printsFlatQueryParamsAsAQueryString(): void
    {
        $this->assertSame(
            'name=Ada&page=2',
            WritingUtils::printQueryParamsAsString(['name' => 'Ada', 'page' => '2']),
        );
    }

    #[UnitTest]
    #[Test]
    public function printsNestedQueryParamsWithBracketNotation(): void
    {
        $this->assertSame(
            'filters[active]=1',
            WritingUtils::printQueryParamsAsString(['filters' => ['active' => '1']]),
        );
    }

    #[UnitTest]
    #[Test]
    public function urlEncodesQueryParamKeysAndValues(): void
    {
        $this->assertSame(
            'a+b=c%26d',
            WritingUtils::printQueryParamsAsString(['a b' => 'c&d']),
        );
    }

    #[UnitTest]
    #[Test]
    public function printsQueryParamValuesThatAreNotStrings(): void
    {
        // Example values come from the extractor as whatever type the parameter
        // is, and this file declares strict_types — so the urlencode() boundary
        // has to do the coercion itself or every documented integer query
        // parameter takes the run down.
        $this->assertSame(
            'page=2&per_page=25',
            WritingUtils::printQueryParamsAsString(['page' => 2, 'per_page' => 25]),
        );
        $this->assertSame(
            'active=1&archived=',
            WritingUtils::printQueryParamsAsString(['active' => true, 'archived' => false]),
        );
        // A list has integer keys, which are printed as `[]` rather than encoded.
        $this->assertSame(
            'ids[]=1&ids[]=2',
            WritingUtils::printQueryParamsAsString(['ids' => [1, 2]]),
        );
    }

    #[UnitTest]
    #[Test]
    public function expandsPrimitiveFormDataParam(): void
    {
        $this->assertSame(
            ['name' => 'John'],
            WritingUtils::getParameterNamesAndValuesForFormData('name', 'John'),
        );
    }

    #[UnitTest]
    #[Test]
    public function expandsListFormDataParam(): void
    {
        $this->assertSame(
            ['filter[]' => 'haha'],
            WritingUtils::getParameterNamesAndValuesForFormData('filter', ['haha']),
        );
    }

    #[UnitTest]
    #[Test]
    public function expandsHashFormDataParam(): void
    {
        $this->assertSame(
            ['filter[name]' => 'john', 'filter[age]' => 12],
            WritingUtils::getParameterNamesAndValuesForFormData('filter', ['name' => 'john', 'age' => 12]),
        );
    }

    #[UnitTest]
    #[Test]
    public function buildsAFriendlyHtmlListOfValues(): void
    {
        $this->assertSame('<code>1</code>', WritingUtils::getListOfValuesAsFriendlyHtmlString([1]));
        $this->assertSame('<code>1</code> or <code>2</code>', WritingUtils::getListOfValuesAsFriendlyHtmlString([1, 2]));
        $this->assertSame(
            '<code>1</code>, <code>2</code>, or <code>3</code>',
            WritingUtils::getListOfValuesAsFriendlyHtmlString([1, 2, 3]),
        );
        $this->assertSame(
            '<code>a</code>, <code>b</code>, and <code>c</code>',
            WritingUtils::getListOfValuesAsFriendlyHtmlString(['a', 'b', 'c'], 'and'),
        );
    }

    #[UnitTest]
    #[Test]
    public function versionsAnAssetPathUsingTheScribeVersion(): void
    {
        $this->assertSame(
            'js/tryitout-' . Scribe::VERSION . '.js',
            WritingUtils::getVersionedAsset('js/tryitout.js'),
        );
    }

    #[UnitTest]
    #[Test]
    public function printsPhpValuesAsValidPhp(): void
    {
        $output = WritingUtils::printPhpValue(['a' => 1, 'b' => 'two']);

        $this->assertStringContainsString("'a' => 1", $output);
        $this->assertStringContainsString("'b' => 'two'", $output);
    }

    #[UnitTest]
    #[Test]
    public function buildsASampleBodyFromNestedBodyParameters(): void
    {
        $nested = [
            'name' => ['type' => 'string', 'example' => 'Ada', '__fields' => []],
            'address' => [
                'type' => 'object',
                'example' => [],
                '__fields' => [
                    'city' => ['type' => 'string', 'example' => 'Lovelace', '__fields' => []],
                ],
            ],
        ];

        $this->assertSame(
            ['name' => 'Ada', 'address' => ['city' => 'Lovelace']],
            WritingUtils::getSampleBody($nested),
        );
    }

    #[UnitTest]
    #[Test]
    public function printsDeeplyNestedQueryParamsWithBracketNotation(): void
    {
        $this->assertSame(
            'filter[info][name]=john',
            WritingUtils::printQueryParamsAsString(['filter' => ['info' => ['name' => 'john']]]),
        );
    }

    #[UnitTest]
    #[Test]
    public function printsAListQueryParamWithEmptyBrackets(): void
    {
        $this->assertSame(
            'tags[]=a&tags[]=b',
            WritingUtils::printQueryParamsAsString(['tags' => ['a', 'b']]),
        );
    }

    #[UnitTest]
    #[Test]
    public function printsQueryParamsAsAnIndentedKeyValueHash(): void
    {
        $output = WritingUtils::printQueryParamsAsKeyValue(['name' => 'Ada', 'page' => 2]);

        $this->assertSame(<<<'JSON'
            {
                "name": "Ada",
                "page": "2",
            }
            JSON, $output);
    }

    #[UnitTest]
    #[Test]
    public function keyValueQueryParamsHonourCustomQuotesDelimitersAndBraces(): void
    {
        // The docs render the same parameter list as PHP, JS, Python and bash,
        // and every one of those is this method with different punctuation.
        $output = WritingUtils::printQueryParamsAsKeyValue(
            ['name' => 'Ada'],
            quote: "'",
            delimiter: ' =>',
            spacesIndentation: 2,
            braces: '[]',
            closingBraceIndentation: 0,
            startLinesWith: '',
            endLinesWith: ',',
        );

        $this->assertSame("[\n  'name' => 'Ada',\n]", $output);
    }

    #[UnitTest]
    #[Test]
    public function keyValueQueryParamsCanOmitTheBracesEntirely(): void
    {
        // bash examples have no surrounding braces at all.
        $this->assertSame(
            "    \"name\": \"Ada\",\n",
            WritingUtils::printQueryParamsAsKeyValue(['name' => 'Ada'], braces: ''),
        );
    }

    #[UnitTest]
    #[Test]
    public function keyValueQueryParamsRenderBooleansAsOneAndZero(): void
    {
        // A raw PHP false interpolates to an empty string, which reads as a
        // missing value rather than as `false`.
        $output = WritingUtils::printQueryParamsAsKeyValue(['active' => true, 'archived' => false], braces: '');

        $this->assertStringContainsString('"active": "1"', $output);
        $this->assertStringContainsString('"archived": "0"', $output);
    }

    #[UnitTest]
    #[Test]
    public function keyValueQueryParamsExpandListsAndHashes(): void
    {
        $output = WritingUtils::printQueryParamsAsKeyValue([
            'tags' => ['a', 'b'],
            'filter' => ['info' => ['name' => 'john']],
        ], braces: '');

        $this->assertStringContainsString('"tags[0]": "a"', $output);
        $this->assertStringContainsString('"tags[1]": "b"', $output);
        $this->assertStringContainsString('"filter[info][name]": "john"', $output);
    }

    #[UnitTest]
    #[Test]
    public function keyValueQueryParamsSkipAnEmptyArrayValue(): void
    {
        $this->assertSame('', WritingUtils::printQueryParamsAsKeyValue(['tags' => []], braces: ''));
    }

    #[UnitTest]
    #[Test]
    public function expandsAListOfObjectsAsFormData(): void
    {
        $this->assertSame(
            ['items[][name]' => 'john', 'items[][age]' => 12],
            WritingUtils::getParameterNamesAndValuesForFormData('items', [['name' => 'john', 'age' => 12]]),
        );
    }

    #[UnitTest]
    #[Test]
    public function expandsANestedHashAsFormData(): void
    {
        $this->assertSame(
            ['filter[info][name]' => 'john'],
            WritingUtils::getParameterNamesAndValuesForFormData('filter', ['info' => ['name' => 'john']]),
        );
    }

    #[UnitTest]
    #[Test]
    public function buildsASampleBodyForATopLevelArrayEndpoint(): void
    {
        // An endpoint whose whole body is an array is expressed with the `[]`
        // pseudo-field, and has to render as a one-element list.
        $this->assertSame(
            [['name' => 'Ada']],
            WritingUtils::getSampleBody([
                '[]' => ['type' => 'object[]', 'example' => [], '__fields' => [
                    'name' => ['type' => 'string', 'example' => 'Ada', '__fields' => []],
                ]],
            ]),
        );
    }

    #[UnitTest]
    #[Test]
    public function buildsASampleBodyForANestedArrayOfObjects(): void
    {
        $this->assertSame(
            ['items' => [['name' => 'Ada']]],
            WritingUtils::getSampleBody([
                'items' => ['type' => 'object[]', 'example' => [], '__fields' => [
                    'name' => ['type' => 'string', 'example' => 'Ada', '__fields' => []],
                ]],
            ]),
        );
    }
}
