<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Writing;

use Hypervel\Support\Str;
use Ipsocode\Camel\Extraction\Response;
use Ipsocode\Camel\Output\OutputEndpointData;
use Ipsocode\Camel\Output\Parameter;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use Ramsey\Uuid\Uuid;

class PostmanCollectionWriter
{
    /**
     * The Postman collection schema version
     * (https://schema.getpostman.com/json/collection/v2.1.0/collection.json).
     */
    public const SPEC_VERSION = '2.1.0';

    protected DocumentationConfig $config;

    protected string $baseUrl;

    public function __construct(?DocumentationConfig $config = null)
    {
        $this->config = $config ?: new DocumentationConfig(config('scribe', []));
        $this->baseUrl = $this->config->get('base_url') ?: config('app.url');
    }

    /**
     * @param array<int, array{description: string, name: string, endpoints: array<int, OutputEndpointData>}> $groupedEndpoints
     * @return array<string, mixed>
     */
    public function generatePostmanCollection(array $groupedEndpoints): array
    {
        $collection = [
            'variable' => [
                [
                    'id' => 'baseUrl',
                    'key' => 'baseUrl',
                    'type' => 'string',
                    'name' => 'string',
                    'value' => $this->baseUrl,
                ],
            ],
            'info' => [
                'name' => $this->config->get('title') ?: config('app.name'),
                '_postman_id' => Uuid::uuid4()->toString(),
                'description' => $this->config->get('description', ''),
                'schema' => 'https://schema.getpostman.com/json/collection/v' . self::SPEC_VERSION . '/collection.json',
            ],
            'item' => array_values(array_map(function (array $group) {
                return [
                    'name' => $group['name'],
                    'description' => $group['description'],
                    'item' => $this->generateSubItem($group),
                ];
            }, $groupedEndpoints)),
            'auth' => $this->generateAuthObject(),
        ];

        return $collection;
    }

    /**
     * @return array<string, mixed>
     */
    protected function generateAuthObject(): array
    {
        if (! $this->config->get('auth.enabled')) {
            return [
                'type' => 'noauth',
            ];
        }

        return match ($this->config->get('auth.in')) {
            // Postman's API key auth supports only `header` and `query`; a body
            // token is documented as a body parameter on each endpoint.
            'body' => [
                'type' => 'noauth',
            ],
            'basic' => [
                'type' => 'basic',
            ],
            'bearer' => [
                'type' => 'bearer',
                'bearer' => [
                    [
                        'key' => $this->config->get('auth.name'),
                        'type' => 'string',
                    ],
                ],
            ],
            default => [
                'type' => 'apikey',
                'apikey' => [
                    [
                        'key' => 'in',
                        'value' => $this->config->get('auth.in') === 'query_or_body' ? 'query' : $this->config->get('auth.in'),
                        'type' => 'string',
                    ],
                    [
                        'key' => 'key',
                        'value' => $this->config->get('auth.name'),
                        'type' => 'string',
                    ],
                ],
            ],
        };
    }

    /**
     * @param array{name: string, description: string, endpoints: array<int, OutputEndpointData>} $group
     * @return array<int, array<string, mixed>>
     */
    protected function generateSubItem(array $group): array
    {
        $seenSubgroups = [];
        $items = [];

        /** @var OutputEndpointData $endpoint */
        foreach ($group['endpoints'] as $endpoint) {
            if (! $endpoint->metadata->subgroup) {
                $items[] = $this->generateEndpointItem($endpoint);
            } else {
                if (isset($seenSubgroups[$endpoint->metadata->subgroup])) {
                    $subgroupIndex = $seenSubgroups[$endpoint->metadata->subgroup];
                    $items[$subgroupIndex]['description'] = $items[$subgroupIndex]['description'] ?: $endpoint->metadata->subgroupDescription;
                    $items[$subgroupIndex]['item'] = [...$items[$subgroupIndex]['item'], $this->generateEndpointItem($endpoint)];
                } else {
                    $items[] = [
                        'name' => $endpoint->metadata->subgroup,
                        'description' => $endpoint->metadata->subgroupDescription,
                        'item' => [$this->generateEndpointItem($endpoint)],
                    ];
                    $seenSubgroups[$endpoint->metadata->subgroup] = count($items) - 1;
                }
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    protected function generateEndpointItem(OutputEndpointData $endpoint): array
    {
        $method = $endpoint->httpMethods[0];

        $bodyParameters = empty($endpoint->bodyParameters) ? null : $this->getBodyData($endpoint);

        if ((in_array('PUT', $endpoint->httpMethods) || in_array('PATCH', $endpoint->httpMethods))
            && isset($bodyParameters['formdata'])) {
            $method = 'POST';
            $bodyParameters['formdata'][] = [
                'key' => '_method',
                'value' => $endpoint->httpMethods[0],
                'type' => 'text',
            ];
        }

        $endpointItem = [
            'name' => ($endpoint->metadata->title !== '' ? $endpoint->metadata->title : ($endpoint->httpMethods[0] . ' ' . $endpoint->uri))
                . ($endpoint->metadata->deprecated ? ' [DEPRECATED]' : ''),
            'request' => [
                'url' => $this->generateUrlObject($endpoint),
                'method' => $method,
                'header' => $this->resolveHeadersForEndpoint($endpoint),
                'body' => $bodyParameters,
                'description' => $endpoint->metadata->description,
            ],
            'response' => $this->getResponses($endpoint),
        ];

        if ($endpoint->metadata->authenticated === false) {
            $endpointItem['request']['auth'] = ['type' => 'noauth'];
        }

        return $endpointItem;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getBodyData(OutputEndpointData $endpoint): array
    {
        $body = [];
        $contentType = $endpoint->headers['Content-Type'] ?? null;
        $inputMode = match ($contentType) {
            'multipart/form-data' => 'formdata',
            'application/x-www-form-urlencoded' => 'urlencoded',
            default => 'raw',
        };
        $body['mode'] = $inputMode;
        $body[$inputMode] = [];

        switch ($inputMode) {
            case 'formdata':
            case 'urlencoded':
                $body[$inputMode] = $this->getFormDataParams(
                    $endpoint->cleanBodyParameters,
                    null,
                    $endpoint->bodyParameters
                );
                foreach ($endpoint->fileParameters as $key => $value) {
                    while (is_array($value)) {
                        $keys = array_keys($value);
                        if ($keys[0] === 0) {
                            // List of files
                            $key .= '[]';
                            $value = $value[0];
                        } else {
                            $key .= '[' . $keys[0] . ']';
                            $value = $value[$keys[0]];
                        }
                    }
                    $params = [
                        'key' => $key,
                        'src' => [],
                        'type' => 'file',
                    ];
                    $body[$inputMode][] = $params;
                }

                break;
            case 'raw':
            default:
                $body[$inputMode] = json_encode($endpoint->cleanBodyParameters, JSON_UNESCAPED_UNICODE);
        }

        return $body;
    }

    /**
     * Format form-data parameters correctly for arrays, e.g. data[item][index] = value.
     *
     * @param array<int|string, mixed> $paramsKeyValue
     * @param array<string, Parameter> $paramsFullDetails
     * @return array<int, array<string, int|string>>
     */
    protected function getFormDataParams(array $paramsKeyValue, int|string|null $key = null, array $paramsFullDetails = []): array
    {
        $body = [];

        foreach ($paramsKeyValue as $index => $value) {
            $index = $key ? ($key . '[' . $index . ']') : $index;

            if (! is_array($value)) {
                $body[] = [
                    'key' => $index,
                    'value' => (string) $value,
                    'type' => 'text',
                    'description' => $paramsFullDetails[$index]->description ?? '',
                ];

                continue;
            }

            $body = array_merge($body, $this->getFormDataParams($value, $index));
        }

        return $body;
    }

    /**
     * @return array<int, array{key: string, value: string}>
     */
    protected function resolveHeadersForEndpoint(OutputEndpointData $endpointData): array
    {
        [$where, $authParam] = $this->getAuthParamToExclude();

        $headers = collect($endpointData->headers);
        if ($where === 'header') {
            unset($headers[$authParam]);
        }

        $headers = $headers
            ->union([
                'Accept' => 'application/json',
            ])
            ->map(function ($value, $header) {
                // Allow users to write ['header' => '@{{value}}'] in config
                // and have it rendered properly as {{value}} in the Postman collection.
                $value = str_replace('@{{', '{{', $value);

                return [
                    'key' => $header,
                    'value' => $value,
                ];
            })
            ->values()
            ->all();

        return $headers;
    }

    /**
     * Flatten a query value into one `name[a][b]` => value entry per leaf, the
     * way parse_str() reads nested arrays.
     *
     * @param array<int|string, mixed> $values
     * @return array<string, string>
     */
    protected function flattenQueryValues(string $prefix, array $values): array
    {
        $flat = [];
        foreach ($values as $index => $value) {
            if (is_array($value)) {
                $flat += $this->flattenQueryValues("{$prefix}[{$index}]", $value);
            } else {
                $flat["{$prefix}[{$index}]"] = (string) $value;
            }
        }

        return $flat;
    }

    /**
     * @return array<string, mixed>
     */
    protected function generateUrlObject(OutputEndpointData $endpointData): array
    {
        $base = [
            'host' => '{{baseUrl}}',
            // Route parameters ({example}) become Postman's colon-prefixed :example.
            'path' => preg_replace_callback('/\{(\w+)\??}/', function ($matches) {
                return ':' . $matches[1];
            }, $endpointData->uri),
        ];

        $query = [];
        [$where, $authParam] = $this->getAuthParamToExclude();

        /** @var Parameter $parameterData */
        foreach ($endpointData->queryParameters as $name => $parameterData) {
            if ($where === 'query' && $authParam === $name) {
                continue;
            }

            if (Str::endsWith($parameterData->type, '[]') || $parameterData->type === 'object') {
                $values = empty($parameterData->example) ? [] : $parameterData->example;
                foreach ($this->flattenQueryValues($name, $values) as $key => $value) {
                    // parse_str() reads arrays as filters[0]=name&filters[1]=age or
                    // filters[]=name&filters[]=age; the indexed form also covers objects.
                    $query[] = [
                        'key' => $key,
                        'value' => $value,
                        'description' => strip_tags($parameterData->description),
                        // Optional parameters without an example start disabled.
                        'disabled' => ! $parameterData->required && empty($parameterData->example),
                    ];
                }
                // If there are no values, add one entry so the parameter shows up in the Postman UI.
                if (empty($values)) {
                    $query[] = [
                        'key' => "{$name}[]",
                        'value' => '',
                        'description' => strip_tags($parameterData->description),
                        'disabled' => true,
                    ];
                }
            } else {
                $query[] = [
                    'key' => $name,
                    'value' => $parameterData->example !== null ? (string) $parameterData->example : '',
                    'description' => strip_tags($parameterData->description),
                    'disabled' => ! $parameterData->required && empty($parameterData->example),
                ];
            }
        }

        $base['query'] = $query;

        // The raw URL is what Insomnia reads on import. Postman v2.1 stores
        // query[].key/value unencoded and encodes them when sending, but the raw
        // string has to be a valid URL, so only it is encoded.
        $queryString = collect($base['query'])->map(function ($queryParamData) {
            return rawurlencode($queryParamData['key']) . '=' . rawurlencode($queryParamData['value']);
        })->implode('&');
        $base['raw'] = sprintf('%s/%s%s', $base['host'], $base['path'], $queryString ? "?{$queryString}" : null);

        $urlParams = collect($endpointData->urlParameters);
        if ($urlParams->isEmpty()) {
            return $base;
        }

        $base['variable'] = $urlParams->map(function (Parameter $parameter, $name) {
            return [
                'id' => $name,
                'key' => $name,
                'value' => $parameter->example,
                'description' => $parameter->description,
            ];
        })->values()->toArray();

        return $base;
    }

    protected function getResponseDescription(Response $response): string
    {
        if ($response->isBinary()) {
            return mb_trim(str_replace('<<binary>>', '', $response->content));
        }

        $description = (string) $response->description;
        // The status code is recorded separately, so drop it from a "200, OK" style description.
        if (preg_match('/\d{3},\s+(.+)/', $description, $matches)) {
            $description = $matches[1];
        } elseif ($description === (string) $response->status) {
            $description = '';
        }

        return $description;
    }

    /**
     * @return array{0: null|string, 1: null|string}
     */
    private function getAuthParamToExclude(): array
    {
        if (! $this->config->get('auth.enabled')) {
            return [null, null];
        }

        if (in_array($this->config->get('auth.in'), ['bearer', 'basic'])) {
            return ['header', 'Authorization'];
        }

        return [$this->config->get('auth.in'), $this->config->get('auth.name')];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getResponses(OutputEndpointData $endpoint): array
    {
        return collect($endpoint->responses)->map(function (Response $response) {
            $headers = [];
            foreach ($response->headers as $header => $value) {
                $headers[] = [
                    'key' => $header,
                    'value' => $value,
                ];
            }

            return [
                'header' => $headers,
                'code' => $response->status,
                'body' => $response->content,
                'name' => $this->getResponseDescription($response),
            ];
        })->toArray();
    }
}
