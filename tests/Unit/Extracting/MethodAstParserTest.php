<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit\Extracting;

use Exception;
use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Scribe\Extracting\MethodAstParser;
use Ipsocode\Scribe\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

/**
 * Inline validation is read out of a controller method's parsed source, so the
 * parser is between Scribe and every controller in an application. Its job when
 * php-parser fails is to say which stage failed, in Scribe's own terms — the
 * raw parser errors name a token and a line in a file the user did not know was
 * being parsed.
 *
 * The two failures are provoked directly: a controller whose source could not
 * be parsed would never have loaded in the first place.
 */
class MethodAstParserTest extends TestCase
{
    private function parse(string $sourceCode): ?array
    {
        return (new ReflectionMethod(MethodAstParser::class, 'parseClassSourceCode'))
            ->invoke(null, $sourceCode);
    }

    #[UnitTest]
    #[Test]
    public function parsesAClassIntoStatements(): void
    {
        $this->assertNotEmpty($this->parse('<?php class Foo { public function bar() {} }'));
    }

    #[UnitTest]
    #[Test]
    public function reportsSourceItCannotParse(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Parse error');

        $this->parse('<?php class Foo { public function');
    }

    #[UnitTest]
    #[Test]
    public function reportsSourceItCanParseButNotResolve(): void
    {
        // Valid syntax, but the name resolver cannot make sense of it: two
        // imports claiming the same short name.
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Traverse error');

        $this->parse('<?php namespace A; use B\Thing; use C\Thing; class Foo {}');
    }
}
