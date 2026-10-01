<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests;

use Ipsocode\Camel\Output\OutputEndpointData;

trait CreatesMockEndpoints
{
    /**
     * Build an OutputEndpointData from a partial spec (dot-notation overrides).
     */
    protected function createMockEndpointData(array $custom = []): OutputEndpointData
    {
        $data = [
            'uri' => 'api/users',
            'httpMethods' => ['GET'],
            'headers' => ['Content-Type' => 'application/json'],
            'metadata' => [
                'title' => 'List users',
                'description' => 'Returns users.',
                'authenticated' => false,
                'groupName' => 'Users',
            ],
            'urlParameters' => [],
            'queryParameters' => [],
            'bodyParameters' => [],
            'responses' => [
                ['status' => 200, 'content' => '{"data":[]}', 'description' => 'OK'],
            ],
            'responseFields' => [],
        ];

        foreach ($custom as $key => $value) {
            data_set($data, $key, $value);
        }

        return OutputEndpointData::create($data);
    }

    /**
     * @param OutputEndpointData[] $endpoints
     */
    protected function createGroup(array $endpoints, string $name = 'Users', string $description = 'User endpoints.'): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'endpoints' => $endpoints,
        ];
    }
}
