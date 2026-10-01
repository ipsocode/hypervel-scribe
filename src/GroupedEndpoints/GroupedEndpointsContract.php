<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\GroupedEndpoints;

interface GroupedEndpointsContract
{
    public function get(): array;

    public function hasEncounteredErrors(): bool;
}
