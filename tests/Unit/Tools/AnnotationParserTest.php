<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit\Tools;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Scribe\Tests\Unit\TestCase;
use Ipsocode\Scribe\Tools\AnnotationParser;
use PHPUnit\Framework\Attributes\Test;

class AnnotationParserTest extends TestCase
{
    #[UnitTest]
    #[Test]
    public function parsesContentAndNamedFields(): void
    {
        $result = AnnotationParser::parseIntoContentAndFields(
            'status=400 when="things go wrong" {"message": "failed"}',
            ['status', 'when'],
        );

        $this->assertSame('{"message": "failed"}', $result['content']);
        $this->assertSame('400', $result['fields']['status']);
        $this->assertSame('things go wrong', $result['fields']['when']);
    }

    #[UnitTest]
    #[Test]
    public function fieldsAreOptionalAndDefaultToNull(): void
    {
        $result = AnnotationParser::parseIntoContentAndFields('just some content', ['status', 'scenario']);

        $this->assertSame('just some content', $result['content']);
        $this->assertNull($result['fields']['status']);
        $this->assertNull($result['fields']['scenario']);
    }

    #[UnitTest]
    #[Test]
    public function fieldsCanAppearAtTheStartOrEndOfTheString(): void
    {
        $atEnd = AnnotationParser::parseIntoContentAndFields('{"a": 1} status=201', ['status']);
        $this->assertSame('{"a": 1}', $atEnd['content']);
        $this->assertSame('201', $atEnd['fields']['status']);

        $atStart = AnnotationParser::parseIntoContentAndFields('status=201 {"a": 1}', ['status']);
        $this->assertSame('{"a": 1}', $atStart['content']);
        $this->assertSame('201', $atStart['fields']['status']);
    }

    #[UnitTest]
    #[Test]
    public function parsesArbitraryKeyValueFields(): void
    {
        $fields = AnnotationParser::parseIntoFields('title=This message="everything good" ignored');

        $this->assertSame(['title' => 'This', 'message' => 'everything good'], $fields);
    }

    #[UnitTest]
    #[Test]
    public function parseIntoFieldsReturnsEmptyArrayWhenNoPairs(): void
    {
        $this->assertSame([], AnnotationParser::parseIntoFields('no fields here'));
    }
}
