<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

/**
 * A pure enum, with no backing type.
 *
 * Scribe reads a URL parameter's type and example off the enum it is bound to,
 * and both of those come from the backing type — which this one does not have.
 * Documenting it as a plain string beats failing the endpoint.
 */
enum Direction
{
    case Ascending;
    case Descending;
}
