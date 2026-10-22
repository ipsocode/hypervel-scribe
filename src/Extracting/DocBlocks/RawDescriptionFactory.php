<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\DocBlocks;

use phpDocumentor\Reflection\DocBlock\Description;
use phpDocumentor\Reflection\DocBlock\DescriptionFactory;
use phpDocumentor\Reflection\Types\Context as TypeContext;

/**
 * Keeps a description as written: no inline tags, and no `{}` read as an
 * escaped `}`.
 */
class RawDescriptionFactory extends DescriptionFactory
{
    public function create(string $contents, ?TypeContext $context = null): Description
    {
        return self::describe($contents);
    }

    public static function describe(string $contents): Description
    {
        // A description is a vsprintf() template.
        return new Description(str_replace('%', '%%', $contents));
    }
}
