<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

/**
 * An int-backed enum, for the annotation paths that must turn a backed enum's
 * value into a string.
 */
enum Priority: int
{
    case Low = 1;
    case High = 2;
}
