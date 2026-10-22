<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\DocBlocks;

/**
 * One tag of a parsed docblock: its name and the text that follows it.
 */
class DocBlockTag
{
    public function __construct(
        protected string $name,
        protected string $content,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The text after the tag name, trimmed, with line breaks kept.
     */
    public function getContent(): string
    {
        return $this->content;
    }

    public function getDescription(): string
    {
        return $this->content;
    }
}
