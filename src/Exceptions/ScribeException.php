<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Exceptions;

/**
 * Marks the exceptions Scribe throws deliberately. They should not be swallowed:
 * they are meant to stop the task and reach the user.
 */
interface ScribeException
{
}
