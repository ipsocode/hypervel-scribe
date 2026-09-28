<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Shared;

use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Camel\Extraction\Response;
use Ipsocode\Scribe\Extracting\ParamHelpers;
use stdClass;

class ResponseFieldTools
{
    use ParamHelpers;

    public static function inferTypeOfResponseField(array $data, ExtractedEndpointData $endpointData): string
    {
        if (! empty($data['type'])) {
            return self::normalizeTypeName($data['type']);
        }

        // Try to get a type from first 2xx response
        $validResponse = collect($endpointData->responses)->first(
            fn (Response $r) => $r->status >= 200 && $r->status < 300
        );
        if ($validResponse && ($validResponseContent = json_decode($validResponse->content, true))) {
            $nonexistent = new stdClass;
            $value = $validResponseContent[$data['name']]
                ?? $validResponseContent['data'][$data['name']] // Maybe it's a Hypervel ApiResource
                ?? $validResponseContent[0][$data['name']] // Maybe it's a list
                ?? $validResponseContent['data'][0][$data['name']] // Maybe an Api Resource Collection?
                ?? $nonexistent;

            if ($value !== $nonexistent) {
                return self::normalizeTypeName(gettype($value), $value);
            }
        }

        return '';
    }
}
