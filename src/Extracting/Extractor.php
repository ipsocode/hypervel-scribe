<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting;

use Hypervel\Http\Testing\File;
use Hypervel\Http\UploadedFile;
use Hypervel\Routing\Route;
use Hypervel\Support\Arr;
use Hypervel\Support\Str;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Camel\Extraction\Metadata;
use Ipsocode\Camel\Extraction\Parameter;
use Ipsocode\Camel\Extraction\ResponseCollection;
use Ipsocode\Camel\Extraction\ResponseField;
use Ipsocode\Camel\Output\OutputEndpointData;
use Ipsocode\Scribe\Extracting\Strategies\StaticData;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use Ipsocode\Scribe\Tools\Globals;
use Ipsocode\Scribe\Tools\RoutePatternMatcher;

class Extractor
{
    use ParamHelpers;

    private DocumentationConfig $config;

    private static ?Route $routeBeingProcessed = null;

    public function __construct(?DocumentationConfig $config = null)
    {
        $this->config = $config ?: new DocumentationConfig(config('scribe'));
    }

    /**
     * The route being extracted, for user code such as a custom strategy or a
     * Scribe hook; null outside processRoute().
     */
    public static function getRouteBeingProcessed(): ?Route
    {
        return self::$routeBeingProcessed;
    }

    public function processRoute(Route $route): ExtractedEndpointData
    {
        self::$routeBeingProcessed = $route;

        try {
            $endpointData = ExtractedEndpointData::fromRoute($route);

            $inheritedDocsOverrides = [];
            if ($endpointData->controller->hasMethod('inheritedDocsOverrides')) {
                $inheritedDocsOverrides = call_user_func([$endpointData->controller->getName(), 'inheritedDocsOverrides']);
                $inheritedDocsOverrides = $inheritedDocsOverrides[$endpointData->method->getName()] ?? [];
            }

            $this->fetchMetadata($endpointData);
            $this->mergeInheritedMethodsData('metadata', $endpointData, $inheritedDocsOverrides);

            $this->fetchUrlParameters($endpointData);
            $this->mergeInheritedMethodsData('urlParameters', $endpointData, $inheritedDocsOverrides);
            $endpointData->cleanUrlParameters = self::cleanParams($endpointData->urlParameters);

            $this->addAuthField($endpointData);

            $this->fetchQueryParameters($endpointData);
            $this->mergeInheritedMethodsData('queryParameters', $endpointData, $inheritedDocsOverrides);
            $endpointData->cleanQueryParameters = self::cleanParams($endpointData->queryParameters);

            $this->fetchRequestHeaders($endpointData);
            $this->mergeInheritedMethodsData('headers', $endpointData, $inheritedDocsOverrides);

            $this->fetchBodyParameters($endpointData);
            $endpointData->cleanBodyParameters = self::cleanParams($endpointData->bodyParameters);
            $this->mergeInheritedMethodsData('bodyParameters', $endpointData, $inheritedDocsOverrides);

            if (count($endpointData->cleanBodyParameters) && ! isset($endpointData->headers['Content-Type'])) {
                // Default to JSON when no strategy set a Content-Type.
                $endpointData->headers['Content-Type'] = 'application/json';
            }
            // Split file parameters out of the body (switching to multipart) here
            // rather than at output time, because response calls send them.
            [$files, $regularParameters] = OutputEndpointData::splitIntoFileAndRegularParameters($endpointData->cleanBodyParameters);
            if (count($files)) {
                $endpointData->headers['Content-Type'] = 'multipart/form-data';
            }
            $endpointData->fileParameters = $files;
            $endpointData->cleanBodyParameters = $regularParameters;

            $this->fetchResponses($endpointData);
            $this->mergeInheritedMethodsData('responses', $endpointData, $inheritedDocsOverrides);

            $this->fetchResponseFields($endpointData);
            $this->mergeInheritedMethodsData('responseFields', $endpointData, $inheritedDocsOverrides);

            if (is_callable(Globals::$__afterExtracting)) {
                call_user_func_array(Globals::$__afterExtracting, [$endpointData]);
            }

            return $endpointData;
        } finally {
            self::$routeBeingProcessed = null;
        }
    }

    public function shouldSkipRoute($route, array $routesToExclude, array $routesToInclude): bool
    {
        if (! empty($routesToExclude) && RoutePatternMatcher::matches($route, $routesToExclude)) {
            return true;
        }
        if (! empty($routesToInclude) && ! RoutePatternMatcher::matches($route, $routesToInclude)) {
            return true;
        }

        return false;
    }

    /**
     * Reduces parameters to the name => example pairs that example requests and
     * response calls use, eg ['age' => new Parameter(['example' => 12, ...])]
     * becomes ['age' => 12]. Drops optional parameters whose example is null and
     * was not set explicitly, turns a file parameter's example into an uploaded
     * file, and nests dotted names into arrays.
     *
     * @param array<string,Parameter> $parameters
     */
    public static function cleanParams(array $parameters): array
    {
        $cleanParameters = [];

        /**
         * @var string $paramName
         * @var Parameter $details
         */
        foreach ($parameters as $paramName => $details) {
            if (! $details->exampleWasSpecified) {
                if (is_null($details->example) && $details->required === false) {
                    continue;
                }
            }

            if ($details->type === 'file') {
                if (is_string($details->example)) {
                    $details->example = self::convertStringValueToUploadedFileInstance($details->example);
                } elseif (is_null($details->example)) {
                    $details->example = (new self)->generateDummyValue($details->type);
                }
            }

            if (Str::startsWith($paramName, '[].')) { // Entire body is an array
                if (empty($parameters['[]'])) { // Make sure there's a parent
                    $cleanParameters['[]'] = [[], []];
                    $parameters['[]'] = new Parameter([
                        'name' => '[]',
                        'type' => 'object[]',
                        'description' => '',
                        'required' => true,
                        'example' => [$paramName => $details->example],
                    ]);
                }
            }

            if (Str::contains($paramName, '.')) { // Object field (or array of objects)
                self::setObject($cleanParameters, $paramName, $details->example, $parameters, $details->required);
            } else {
                $cleanParameters[$paramName] = $details->example;
            }
        }

        // An array body is returned as the list itself.
        if (isset($cleanParameters['[]'])) {
            $cleanParameters = $cleanParameters['[]'];
        }

        return $cleanParameters;
    }

    public static function setObject(array &$results, string $path, $value, array $source, bool $isRequired)
    {
        $parts = explode('.', $path);

        array_pop($parts); // Get rid of the field name

        $baseName = implode('.', $parts);
        // An array field's source entry has no [] suffix: test.items[] is described
        // as name test.items, type object[], so trailing []s are stripped to find it.
        // Other fields (eg test.items[].name) stay as they are.
        $baseNameInOriginalParams = $baseName;
        while (Str::endsWith($baseNameInOriginalParams, '[]')) {
            $baseNameInOriginalParams = mb_substr($baseNameInOriginalParams, 0, -2);
        }
        // When the body is an array, param names are "[].paramname", which
        // leaves $baseNameInOriginalParams empty
        if (Str::startsWith($path, '[].')) {
            $baseNameInOriginalParams = '[]';
        }

        if (Arr::has($source, $baseNameInOriginalParams)) {
            /** @var Parameter $parentData */
            $parentData = Arr::get($source, $baseNameInOriginalParams);
            // Dot path for Arr::get() and Arr::set()
            $dotPath = str_replace('[]', '.0', $path);

            // Don't overwrite data the parent already has
            if ($parentData->type === 'object') {
                $parentPath = explode('.', $dotPath);
                $property = array_pop($parentPath);
                $parentPath = implode('.', $parentPath);

                $exampleFromParent = Arr::get($results, $dotPath) ?? $parentData->example[$property] ?? null;
                if (empty($exampleFromParent)) {
                    Arr::set($results, $dotPath, $value);
                }
            } elseif ($parentData->type === 'object[]') {
                // When the body is an array, param names are "[].paramname", which dot paths can't express
                if (Str::startsWith($path, '[].')) {
                    $valueDotPath = mb_substr($dotPath, 3); // Remove initial '.0.'
                    if (isset($results['[]'][0]) && ! Arr::has($results['[]'][0], $valueDotPath)) {
                        Arr::set($results['[]'][0], $valueDotPath, $value);
                    }
                } else {
                    $parentPath = explode('.', $dotPath);
                    $index = (int) array_pop($parentPath);
                    $parentPath = implode('.', $parentPath);

                    $exampleFromParent = Arr::get($results, $dotPath) ?? $parentData->example[$index] ?? null;
                    if (empty($exampleFromParent)) {
                        Arr::set($results, $dotPath, $value);
                    }
                }
            }
        }
    }

    public function addAuthField(ExtractedEndpointData $endpointData): void
    {
        $isApiAuthed = $this->config->get('auth.enabled', false);
        if (! $isApiAuthed || ! $endpointData->metadata->authenticated) {
            return;
        }

        $strategy = $this->config->get('auth.in');
        $parameterName = $this->config->get('auth.name');

        $token = $this->getFaker()->shuffleString('abcdefghkvaZVDPE1864563');
        $valueToUse = $this->config->get('auth.use_value');
        $valueToDisplay = $this->config->get('auth.placeholder');

        switch ($strategy) {
            case 'query':
            case 'query_or_body':
                $endpointData->auth = ['queryParameters', $parameterName, $valueToUse ?: $token];
                $endpointData->queryParameters[$parameterName] = new Parameter([
                    'name' => $parameterName,
                    'type' => 'string',
                    'example' => $valueToDisplay ?: $token,
                    'description' => 'Authentication key.',
                    'required' => true,
                ]);

                return;
            case 'body':
                $endpointData->auth = ['bodyParameters', $parameterName, $valueToUse ?: $token];
                $endpointData->bodyParameters[$parameterName] = new Parameter([
                    'name' => $parameterName,
                    'type' => 'string',
                    'example' => $valueToDisplay ?: $token,
                    'description' => 'Authentication key.',
                    'required' => true,
                ]);

                return;
            case 'bearer':
                $endpointData->auth = ['headers', 'Authorization', 'Bearer ' . ($valueToUse ?: $token)];
                $endpointData->headers['Authorization'] = 'Bearer ' . ($valueToDisplay ?: $token);

                return;
            case 'basic':
                $endpointData->auth = ['headers', 'Authorization', 'Basic ' . ($valueToUse ?: base64_encode($token))];
                $endpointData->headers['Authorization'] = 'Basic ' . ($valueToDisplay ?: base64_encode($token));

                return;
            case 'header':
                $endpointData->auth = ['headers', $parameterName, $valueToUse ?: $token];
                $endpointData->headers[$parameterName] = $valueToDisplay ?: $token;

                return;
        }
    }

    protected function fetchMetadata(ExtractedEndpointData $endpointData): void
    {
        $endpointData->metadata = new Metadata([
            'groupName' => $this->config->get('groups.default', ''),
            'authenticated' => $this->config->get('auth.default', false),
        ]);

        $this->iterateThroughStrategies('metadata', $endpointData, function ($results) use ($endpointData) {
            foreach ($results as $key => $item) {
                $hadPreviousValue = ! is_null($endpointData->metadata->{$key});
                $noNewValueSet = is_null($item) || $item === '';
                if ($hadPreviousValue && $noNewValueSet) {
                    continue;
                }
                $endpointData->metadata->{$key} = $item;
            }
        });
    }

    protected function fetchUrlParameters(ExtractedEndpointData $endpointData): void
    {
        $this->iterateThroughStrategies('urlParameters', $endpointData, function ($results) use ($endpointData) {
            foreach ($results as $key => $item) {
                if (empty($item['name'])) {
                    $item['name'] = $key;
                }
                $endpointData->urlParameters[$key] = Parameter::create($item, $endpointData->urlParameters[$key] ?? []);
            }
        });
    }

    protected function fetchQueryParameters(ExtractedEndpointData $endpointData): void
    {
        $this->iterateThroughStrategies('queryParameters', $endpointData, function ($results) use ($endpointData) {
            foreach ($results as $key => $item) {
                if (empty($item['name'])) {
                    $item['name'] = $key;
                }
                $endpointData->queryParameters[$key] = Parameter::create($item, $endpointData->queryParameters[$key] ?? []);
            }
        });
    }

    protected function fetchBodyParameters(ExtractedEndpointData $endpointData): void
    {
        $this->iterateThroughStrategies('bodyParameters', $endpointData, function ($results) use ($endpointData) {
            foreach ($results as $key => $item) {
                if (empty($item['name'])) {
                    $item['name'] = $key;
                }
                $endpointData->bodyParameters[$key] = Parameter::create($item, $endpointData->bodyParameters[$key] ?? []);
            }
        });
    }

    protected function fetchResponses(ExtractedEndpointData $endpointData): void
    {
        $this->iterateThroughStrategies('responses', $endpointData, function ($results) use ($endpointData) {
            // Responses from different strategies are all added, not overwritten
            $endpointData->responses->concat($results);
        });
        // Sort by status, so 2xx responses come first
        $endpointData->responses = new ResponseCollection($endpointData->responses->sortBy('status')->values());
    }

    protected function fetchResponseFields(ExtractedEndpointData $endpointData): void
    {
        $this->iterateThroughStrategies('responseFields', $endpointData, function ($results) use ($endpointData) {
            foreach ($results as $key => $item) {
                $endpointData->responseFields[$key] = Parameter::create($item, $endpointData->responseFields[$key] ?? []);
            }
        });
    }

    protected function fetchRequestHeaders(ExtractedEndpointData $endpointData): void
    {
        $this->iterateThroughStrategies('headers', $endpointData, function ($results) use ($endpointData) {
            foreach ($results as $key => $item) {
                if ($item) {
                    $endpointData->headers[$key] = $item;
                }
            }
        });
    }

    /**
     * Runs the stage's configured strategies in order. A strategy either returns
     * an array, which $handler applies to the endpoint data, or edits the
     * endpoint data directly.
     *
     * @param callable $handler receives each array a strategy returns
     */
    protected function iterateThroughStrategies(
        string $stage,
        ExtractedEndpointData $endpointData,
        callable $handler,
    ): void {
        $strategies = $this->config->get("strategies.{$stage}", []);

        foreach ($strategies as $strategyClassOrTuple) {
            if (is_array($strategyClassOrTuple)) {
                [$strategyClass, &$settings] = $strategyClassOrTuple;
                if ($strategyClass === 'static_data') {
                    $strategyClass = StaticData::class;
                    // Static data is either short, ['static_data', ['key' => 'value']], or
                    // extended, ['static_data', ['data' => ['key' => 'value'], 'only' => ['GET *'], 'except' => []]].
                    $settingsFormat = array_key_exists('data', $settings) ? 'extended' : 'short';
                    $settings = match ($settingsFormat) {
                        'extended' => $settings,
                        'short' => ['data' => $settings],
                    };
                }
            } else {
                $strategyClass = $strategyClassOrTuple;
                $settings = [];
            }

            $routesToExclude = Arr::wrap($settings['except'] ?? []);
            $routesToInclude = Arr::wrap($settings['only'] ?? []);

            if ($this->shouldSkipRoute($endpointData->route, $routesToExclude, $routesToInclude)) {
                continue;
            }

            $strategy = new $strategyClass($this->config);
            $results = $strategy($endpointData, $settings);
            if (is_array($results)) {
                $handler($results);
            }
        }
    }

    protected static function convertStringValueToUploadedFileInstance(string $filePath): UploadedFile
    {
        $fileName = basename($filePath);

        return new File($fileName, fopen($filePath, 'r'));
    }

    protected function mergeInheritedMethodsData(string $stage, ExtractedEndpointData $endpointData, array $inheritedDocsOverrides = []): void
    {
        $overrides = $inheritedDocsOverrides[$stage] ?? [];
        $normalizeParamData = fn ($data, $key) => array_merge($data, ['name' => $key]);
        if (is_array($overrides)) {
            foreach ($overrides as $key => $item) {
                switch ($stage) {
                    case 'responses':
                        $endpointData->responses->concat($overrides);
                        $endpointData->responses->sortBy('status');

                        break;
                    case 'urlParameters':
                    case 'bodyParameters':
                    case 'queryParameters':
                        $endpointData->{$stage}[$key] = Parameter::make($normalizeParamData($item, $key));

                        break;
                    case 'responseFields':
                        $endpointData->{$stage}[$key] = ResponseField::make($normalizeParamData($item, $key));

                        break;
                    default:
                        $endpointData->{$stage}[$key] = $item;
                }
            }
        } elseif (is_callable($overrides)) {
            $results = $overrides($endpointData);

            $endpointData->{$stage} = match ($stage) {
                'responses' => ResponseCollection::make($results),
                'urlParameters', 'bodyParameters', 'queryParameters' => collect($results)->map(fn ($param, $name) => Parameter::make($normalizeParamData($param, $name)))->all(),
                'responseFields' => collect($results)->map(fn ($field, $name) => ResponseField::make($normalizeParamData($field, $name)))->all(),
                default => $results,
            };
        }
    }

    public static function flushState(): void
    {
        self::$routeBeingProcessed = null;
    }
}
