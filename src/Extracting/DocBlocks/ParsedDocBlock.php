<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\DocBlocks;

use phpDocumentor\Reflection\DocBlock\Tag;
use phpDocumentor\Reflection\DocBlockFactory;

/**
 * A docblock reduced to what Scribe reads: its summary, its description and
 * its tags as a name and the raw text that follows it.
 *
 * phpDocumentor splits the docblock up. Nothing in it is interpreted beyond
 * that ({@see RawTagFactory}, {@see RawDescriptionFactory}), so a `@response`
 * body or a `@bodyParam` line reaches the strategies exactly as written.
 */
class ParsedDocBlock
{
    protected string $summary = '';

    protected string $description = '';

    /**
     * @var DocBlockTag[]
     */
    protected array $tags = [];

    public function __construct(string $docComment)
    {
        if (trim($docComment) === '') {
            return;
        }

        $tagFactory = new RawTagFactory;
        $docBlock = (new DocBlockFactory(new RawDescriptionFactory($tagFactory), $tagFactory))->create($docComment);

        $this->summary = $docBlock->getSummary();
        $this->description = (string) $docBlock->getDescription();
        $this->tags = array_map(
            fn (Tag $tag) => new DocBlockTag($tag->getName(), $this->contentOf($tag)),
            $docBlock->getTags()
        );
    }

    public function getShortDescription(): string
    {
        return $this->summary;
    }

    public function getLongDescription(): string
    {
        return $this->description;
    }

    /**
     * @return DocBlockTag[]
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    protected function contentOf(Tag $tag): string
    {
        return trim(substr($tag->render(), strlen($tag->getName()) + 1));
    }
}
