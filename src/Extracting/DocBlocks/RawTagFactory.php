<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\DocBlocks;

use InvalidArgumentException;
use phpDocumentor\Reflection\DocBlock\StandardTagFactory;
use phpDocumentor\Reflection\DocBlock\Tag;
use phpDocumentor\Reflection\DocBlock\TagFactory;
use phpDocumentor\Reflection\DocBlock\Tags\Generic;
use phpDocumentor\Reflection\Types\Context as TypeContext;

/**
 * Makes every tag a generic one that keeps its text as written.
 *
 * phpDocumentor's own factory reads `@param`, `@see`, `@mixin` and the like
 * as typed tags, resolving the names in them, and its descriptions turn `{}`
 * into `}`, so an `@response {}` body would lose a brace.
 */
class RawTagFactory implements TagFactory
{
    public function create(string $tagLine, ?TypeContext $context = null): Tag
    {
        if (! preg_match('/^@(' . StandardTagFactory::REGEX_TAGNAME . ')(?:\s*(\S.*)|$)/us', $tagLine, $matches)) {
            throw new InvalidArgumentException("Invalid docblock tag line: {$tagLine}");
        }

        return new Generic($matches[1], RawDescriptionFactory::describe($matches[2] ?? ''));
    }

    /**
     * Tags are never interpreted, so there is nothing to inject.
     *
     * @param mixed $value
     */
    public function addParameter(string $name, $value): void
    {
    }

    /**
     * Tags are never interpreted, so there is nothing to inject.
     */
    public function addService(object $service, ?string $alias = null): void
    {
    }

    /**
     * Every tag is generic, so a handler would never be used.
     *
     * @param mixed $handler
     */
    public function registerTagHandler(string $tagName, $handler): void
    {
    }
}
