<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\GroupedEndpoints;

interface GroupedEndpointsContract
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function get(): array;

    public function hasEncounteredErrors(): bool;
}
