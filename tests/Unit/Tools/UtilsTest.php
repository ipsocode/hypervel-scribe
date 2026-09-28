<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit\Tools;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Scribe\Reflection\DocBlock;
use Ipsocode\Scribe\Tests\Unit\TestCase;
use Ipsocode\Scribe\Tools\Utils;
use PHPUnit\Framework\Attributes\Test;
use stdClass;

class UtilsTest extends TestCase
{
    #[UnitTest]
    #[Test]
    public function bindsUrlParametersWithProvidedExamples(): void
    {
        $this->assertSame(
            'users/3',
            Utils::replaceUrlParameterPlaceholdersWithValues('users/{id}', ['id' => 3]),
        );
    }

    #[UnitTest]
    #[Test]
    public function bindsOptionalUrlParameters(): void
    {
        $this->assertSame(
            'users/3',
            Utils::replaceUrlParameterPlaceholdersWithValues('users/{id?}', ['id' => 3]),
        );
    }

    #[UnitTest]
    #[Test]
    public function stripsUnboundOptionalParameters(): void
    {
        $this->assertSame(
            'users/3/',
            Utils::replaceUrlParameterPlaceholdersWithValues('users/{id}/{slug?}', ['id' => 3]),
        );
    }

    #[UnitTest]
    #[Test]
    public function replacesUnboundRequiredParametersWithOne(): void
    {
        $this->assertSame(
            'posts/7/comments/1',
            Utils::replaceUrlParameterPlaceholdersWithValues('posts/{post}/comments/{comment}', ['post' => 7]),
        );
    }

    #[UnitTest]
    #[Test]
    public function returnsTheUriUntouchedWhenNoParametersAreProvided(): void
    {
        $this->assertSame(
            'users/{id}',
            Utils::replaceUrlParameterPlaceholdersWithValues('users/{id}', []),
        );
    }

    #[UnitTest]
    #[Test]
    public function extractsTopLevelItemsFromAMixedConfigList(): void
    {
        $this->assertSame(
            ['a', 'b', 'c'],
            Utils::getTopLevelItemsFromMixedConfigList(['a', 'b' => ['nested' => 1], 'c']),
        );
    }

    #[UnitTest]
    #[Test]
    public function detectsAndUnwrapsArrayTypes(): void
    {
        $this->assertTrue(Utils::isArrayType('string[]'));
        $this->assertFalse(Utils::isArrayType('string'));
        $this->assertSame('string', Utils::getBaseTypeFromArrayType('string[]'));
    }

    #[UnitTest]
    #[Test]
    public function detectsInvokableObjects(): void
    {
        $invokable = new class {
            public function __invoke(): void
            {
            }
        };

        $this->assertTrue(Utils::isInvokableObject($invokable));
        $this->assertFalse(Utils::isInvokableObject(new stdClass));
        $this->assertFalse(Utils::isInvokableObject('not an object'));
    }

    #[UnitTest]
    #[Test]
    public function filtersDocblockTagsCaseInsensitively(): void
    {
        $tags = (new DocBlock("/**\n * @group Things\n * @QueryParam page\n * @queryParam per_page\n */"))->getTags();

        $queryParamTags = Utils::filterDocBlockTags($tags, 'queryparam');

        $this->assertCount(2, $queryParamTags);
        // array_values() rekeys, so the filtered result has no gaps.
        $this->assertSame([0, 1], array_keys($queryParamTags));
    }
}
