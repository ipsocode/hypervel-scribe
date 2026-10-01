<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

/**
 * A backed enum for the annotation paths that accept one.
 *
 * Scribe lets a group name, a subgroup name or a parameter's `enum:` be a PHP
 * enum rather than a literal, and each of those unwraps it differently — so the
 * Workbench app carries a real enum instead of the tests inventing one each time.
 */
enum PostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
