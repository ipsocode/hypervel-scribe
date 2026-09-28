<?php

declare(strict_types=1);

namespace Workbench\App\Support;

/**
 * A dependency that cannot be constructed without arguments, which is what
 * stops Scribe reflecting a controller argument into an example model.
 */
class NeedsArguments
{
    public function __construct(public string $prefix)
    {
    }
}
