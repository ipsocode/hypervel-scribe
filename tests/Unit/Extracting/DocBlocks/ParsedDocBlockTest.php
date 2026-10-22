<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit\Extracting\DocBlocks;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Scribe\Extracting\DocBlocks\ParsedDocBlock;
use Ipsocode\Scribe\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ParsedDocBlockTest extends TestCase
{
    #[UnitTest]
    #[Test]
    public function anEmptyCommentHasNothing(): void
    {
        $docBlock = new ParsedDocBlock('');

        $this->assertSame('', $docBlock->getShortDescription());
        $this->assertSame('', $docBlock->getLongDescription());
        $this->assertSame([], $docBlock->getTags());
    }

    #[UnitTest]
    #[Test]
    public function splitsTheSummaryFromTheDescription(): void
    {
        $docBlock = new ParsedDocBlock("/**\n * List users.\n *\n * Returns every user,\n * newest first.\n */");

        $this->assertSame('List users.', $docBlock->getShortDescription());
        $this->assertSame("Returns every user,\nnewest first.", $docBlock->getLongDescription());
    }

    #[UnitTest]
    #[Test]
    public function readsTagsAsANameAndTheRawTextThatFollows(): void
    {
        $docBlock = new ParsedDocBlock(<<<'DOC'
            /**
             * @group Users
             * @authenticated
             * @urlParam id int required The id. Example: 4
             * @response 201 {
             *   "id": 1
             * }
             * @mixin \App\Models\User
             */
            DOC);

        $tags = array_map(fn ($tag) => [$tag->getName(), $tag->getContent(), $tag->getDescription()], $docBlock->getTags());

        $this->assertSame([
            ['group', 'Users', 'Users'],
            ['authenticated', '', ''],
            ['urlParam', 'id int required The id. Example: 4', 'id int required The id. Example: 4'],
            ['response', "201 {\n  \"id\": 1\n}", "201 {\n  \"id\": 1\n}"],
            ['mixin', '\App\Models\User', '\App\Models\User'],
        ], $tags);
    }
}
