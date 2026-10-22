<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit\Extracting\DocBlocks;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use InvalidArgumentException;
use Ipsocode\Scribe\Extracting\DocBlocks\RawTagFactory;
use Ipsocode\Scribe\Tests\Unit\TestCase;
use phpDocumentor\Reflection\DocBlock\Tags\Generic;
use PHPUnit\Framework\Attributes\Test;
use stdClass;

class RawTagFactoryTest extends TestCase
{
    #[UnitTest]
    #[Test]
    public function makesAGenericTagFromATagLine(): void
    {
        $tag = (new RawTagFactory)->create('@response 200 {}');

        $this->assertInstanceOf(Generic::class, $tag);
        $this->assertSame('response', $tag->getName());
        $this->assertSame('200 {}', (string) $tag);
    }

    #[UnitTest]
    #[Test]
    public function rejectsALineWithoutATagName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid docblock tag line: @ nothing');

        (new RawTagFactory)->create('@ nothing');
    }

    #[UnitTest]
    #[Test]
    public function ignoresHandlersAndServices(): void
    {
        $factory = new RawTagFactory;
        $factory->addParameter('name', 'value');
        $factory->addService(new stdClass);
        $factory->registerTagHandler('param', Generic::class);

        $this->assertSame('1 2', (string) $factory->create('@param 1 2'));
    }
}
