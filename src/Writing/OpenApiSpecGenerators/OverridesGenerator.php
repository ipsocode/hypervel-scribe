<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Writing\OpenApiSpecGenerators;

use Hypervel\Support\Arr;

class OverridesGenerator extends OpenApiGenerator
{
    public function root(array $root, array $groupedEndpoints): array
    {
        $overrides = $this->config->get('openapi.overrides', []);

        return array_replace_recursive($root, Arr::undot($overrides));
    }
}
