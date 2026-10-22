<?php

declare(strict_types=1);

namespace Workbench\App\Strategies;

use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\Strategies\Strategy;
use TypeError;

/**
 * Fails the way a strategy hitting a bad type does: with an Error, which is
 * not an Exception, on the one route named `api/undocumentable/type-error`.
 */
class ThrowsAnErrorStrategy extends Strategy
{
    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        if ($endpointData->uri === 'api/undocumentable/type-error') {
            throw new TypeError('Not a string.');
        }

        return null;
    }
}
