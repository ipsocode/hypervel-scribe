<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies;

use Ipsocode\Camel\Extraction\ExtractedEndpointData;

/**
 * A simple strategy that returns a set of static data.
 */
class StaticData extends Strategy
{
    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        return $settings['data'];
    }

    public static function withSettings(
        array $only = [],
        array $except = [],
        array $data = [],
    ): array {
        return static::wrapWithSettings(
            only: $only,
            except: $except,
            otherSettings: compact(
                'data',
            )
        );
    }
}
