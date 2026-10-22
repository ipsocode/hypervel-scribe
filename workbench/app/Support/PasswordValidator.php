<?php

declare(strict_types=1);

namespace Workbench\App\Support;

/**
 * Not Hypervel's validator: only its name matters to the finder.
 */
final class PasswordValidator
{
    public static function make(mixed $password): bool
    {
        return $password !== null;
    }
}
