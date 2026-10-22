<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Writing\OpenApiSpecGenerators;

use Ipsocode\Camel\Output\OutputEndpointData;

class SecurityGenerator extends OpenApiGenerator
{
    public function root(array $root, array $groupedEndpoints): array
    {
        $isApiAuthed = $this->config->get('auth.enabled', false);
        if (! $isApiAuthed) {
            return $root;
        }

        $location = $this->config->get('auth.in');
        $parameterName = $this->config->get('auth.name');
        $description = $this->config->get('auth.extra_info');
        // A token sent in the body is documented as a body parameter on each
        // authenticated endpoint; OpenAPI has no security scheme for it.
        if ($location === 'body') {
            return $root;
        }

        $scheme = match ($location) {
            // OpenAPI has no "query or body", and the query form is accepted.
            'query', 'query_or_body', 'header' => [
                'type' => 'apiKey',
                'name' => $parameterName,
                'in' => $location === 'header' ? 'header' : 'query',
                'description' => $description,
            ],
            'bearer', 'basic' => [
                'type' => 'http',
                'scheme' => $location,
                'description' => $description,
            ],
            default => [],
        };

        return array_merge_recursive($root, [
            // A scheme is declared in `components.securitySchemes`...
            'components' => [
                'securitySchemes' => [
                    // The scheme's name is arbitrary.
                    'default' => $scheme,
                ],
            ],
            // ...and applied in `security`.
            'security' => [
                [
                    'default' => [],
                ],
            ],
        ]);
    }

    public function pathItem(array $pathItem, array $groupedEndpoints, OutputEndpointData $endpoint): array
    {
        if (! $endpoint->metadata->authenticated) {
            // An empty list opts the operation out of the global `security`.
            $pathItem['security'] = [];
        }

        return $pathItem;
    }
}
