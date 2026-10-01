<?php

declare(strict_types=1);

namespace Ipsocode\Camel\Output;

use Hypervel\Http\UploadedFile;
use Hypervel\Routing\Route;
use Hypervel\Support\Arr;
use Hypervel\Support\Str;
use Ipsocode\Camel\BaseDTO;
use Ipsocode\Camel\Extraction\Metadata;
use Ipsocode\Camel\Extraction\ResponseCollection;
use Ipsocode\Camel\Extraction\ResponseField;
use Ipsocode\Scribe\Extracting\Extractor;
use Ipsocode\Scribe\Tools\Utils as u;
use Ipsocode\Scribe\Tools\WritingUtils;

/**
 * Endpoint DTO for generating output: extraction-only properties removed,
 * output properties and helpers added.
 */
class OutputEndpointData extends BaseDTO
{
    /**
     * @var array<string>
     */
    public array $httpMethods;

    public string $uri;

    public Metadata $metadata;

    /**
     * @var array<string,string>
     */
    public array $headers = [];

    /**
     * @var array<string,Parameter>
     */
    public array $urlParameters = [];

    /**
     * @var array<string,mixed>
     */
    public array $cleanUrlParameters = [];

    /**
     * The optional URL parameters this copy of the endpoint leaves out. OpenAPI
     * cannot mark a path segment optional, so the spec writer publishes one path
     * item per URI a route such as `countries/list/{id?}` serves; this keeps
     * their operation IDs apart.
     *
     * @var array<int,string>
     */
    public array $omittedUrlParameters = [];

    /**
     * @var array<string,Parameter>
     */
    public array $queryParameters = [];

    /**
     * @var array<string,mixed>
     */
    public array $cleanQueryParameters = [];

    /**
     * @var array<string, Parameter>
     */
    public array $bodyParameters = [];

    /**
     * @var array<string,mixed>
     */
    public array $cleanBodyParameters = [];

    /**
     * @var array<string,UploadedFile>
     */
    public array $fileParameters = [];

    public ResponseCollection $responses;

    /**
     * @var array<string,ResponseField>
     */
    public array $responseFields = [];

    /**
     * The body parameters as a hierarchy: top-level items, each with a `__fields`
     * property holding its children, for nested rendering.
     *
     * @var array<string, array>
     */
    public array $nestedBodyParameters = [];

    /**
     * @var array<string, array>
     */
    public array $nestedResponseFields = [];

    public ?string $boundUri;

    public function __construct(array $parameters = [])
    {
        // BaseDTO casts only properties typed as a single class, so the arrays of DTOs are
        // built here, and `responses` gets a collection even when it is missing.
        $parameters['responses'] = new ResponseCollection($parameters['responses'] ?? []);
        $parameters['bodyParameters'] = array_map(fn ($param) => new Parameter($param), $parameters['bodyParameters'] ?? []);
        $parameters['queryParameters'] = array_map(fn ($param) => new Parameter($param), $parameters['queryParameters'] ?? []);
        $parameters['urlParameters'] = array_map(fn ($param) => new Parameter($param), $parameters['urlParameters'] ?? []);
        $parameters['responseFields'] = array_map(fn ($param) => new ResponseField($param), $parameters['responseFields'] ?? []);

        parent::__construct($parameters);

        $this->cleanBodyParameters = Extractor::cleanParams($this->bodyParameters);
        $this->cleanQueryParameters = Extractor::cleanParams($this->queryParameters);
        $this->cleanUrlParameters = Extractor::cleanParams($this->urlParameters);
        $this->nestedBodyParameters = self::nestArrayAndObjectFields($this->bodyParameters, $this->cleanBodyParameters);
        $this->nestedResponseFields = self::nestArrayAndObjectFields($this->responseFields);

        $this->boundUri = u::getUrlWithBoundParameters($this->uri, $this->cleanUrlParameters);

        [$files, $regularParameters] = static::splitIntoFileAndRegularParameters($this->cleanBodyParameters);

        if (count($files)) {
            $this->headers['Content-Type'] = 'multipart/form-data';
        }
        $this->fileParameters = $files;
        $this->cleanBodyParameters = $regularParameters;
    }

    /**
     * @return array<string>
     */
    public static function getMethods(Route $route): array
    {
        $methods = $route->methods();

        // Drops the automatic HEAD, as ExtractedEndpointData::getMethods() does.
        if (count($methods) === 1) {
            return $methods;
        }

        return array_diff($methods, ['HEAD']);
    }

    public static function fromExtractedEndpointArray(array $endpoint): self
    {
        return new self($endpoint);
    }

    /**
     * Nests object and array fields under their parent's `__fields`, adding any
     * parent that was not declared. For example, `dad`, `dad.age`, `dad.cars`
     * and `dad.cars[].model` become
     * ['dad' => [..., '__fields' => ['age' => [...], 'cars' => [..., '__fields' => ['model' => [...]]]]]].
     */
    public static function nestArrayAndObjectFields(array $parameters, array $cleanParameters = []): array
    {
        // Add any parent fields that were not declared.
        $normalisedParameters = [];
        foreach ($parameters as $name => $parameter) {
            if (Str::contains($name, '.')) {
                $ancestors = [];

                $parts = explode('.', $name);
                $fieldName = array_pop($parts);
                $parentName = mb_rtrim(implode('.', $parts), '[]');

                // An array body's fields are named "[].name", which leaves $parentName empty.
                if (empty($parentName)) {
                    $parentName = '[]';
                }

                while ($parentName) {
                    if (! empty($normalisedParameters[$parentName])) {
                        break;
                    }

                    $details = [
                        'name' => $parentName,
                        'type' => $parentName === '[]' ? 'object[]' : 'object',
                        'description' => '',
                        'required' => false,
                    ];

                    if ($parameter instanceof ResponseField) {
                        $ancestors[] = [$parentName, new ResponseField($details)];
                    } else {
                        $lastParentExample = $details['example']
                            = [$fieldName => $lastParentExample ?? $parameter->example];
                        $ancestors[] = [$parentName, new Parameter($details)];
                    }

                    $fieldName = array_pop($parts);
                    $parentName = mb_rtrim(implode('.', $parts), '[]');
                }

                // Outermost ancestor first, so the next loop sees parents before children.
                foreach (array_reverse($ancestors) as [$ancestorName, $ancestor]) {
                    $normalisedParameters[$ancestorName] = $ancestor;
                }
            }

            $normalisedParameters[$name] = $parameter;
            unset($lastParentExample);
        }

        $finalParameters = [];
        foreach ($normalisedParameters as $name => $parameter) {
            $parameter = $parameter->toArray();
            if (Str::contains($name, '.')) { // An object field
                $parts = explode('.', $name);
                $fieldName = array_pop($parts);
                $baseName = implode('.__fields.', $parts);

                // `items[].more` and `items.more` both nest under `items`; the parent's
                // `type` (object[] or object) records the difference, so `[]` is dropped.
                $dotPathToParent = str_replace('[]', '', $baseName);
                // An array body's fields are named "[].name", so $parts is ['[]'].
                if ($parts[0] === '[]') {
                    $dotPathToParent = '[]' . $dotPathToParent;
                }

                $dotPath = $dotPathToParent . '.__fields.' . $fieldName;
                Arr::set($finalParameters, $dotPath, $parameter);
            } else { // A regular field, not a subfield of anything
                // Its subfields come after it (the first loop ensures that) and fill in __fields.
                $parameter['__fields'] = [];
                $finalParameters[$name] = $parameter;
            }
        }

        // An array body has nothing else at the top level.
        if (isset($finalParameters['[]'])) {
            $finalParameters = ['[]' => $finalParameters['[]']];
            // Its example is likely [[], []] here; the clean parameters hold the real one.
            if (
                is_array($finalParameters['[]']['example'])
                && isset($finalParameters['[]']['example'][0])
                && $finalParameters['[]']['example'][0] === []
                && ! empty($cleanParameters)
            ) {
                $finalParameters['[]']['example'] = $cleanParameters;
            }
        }

        return $finalParameters;
    }

    public function endpointId(): string
    {
        return $this->httpMethods[0] . str_replace(['/', '?', '{', '}', ':', '\\', '+', '|', '.'], '-', $this->uri);
    }

    public function name(): string
    {
        return $this->metadata->title ?: ($this->httpMethods[0] . ' ' . $this->uri);
    }

    public function fullSlug(): string
    {
        $groupSlug = Str::slug($this->metadata->groupName);
        $endpointId = $this->endpointId();

        return "{$groupSlug}-{$endpointId}";
    }

    public function hasResponses(): bool
    {
        return count($this->responses) > 0;
    }

    public function hasFiles(): bool
    {
        return count($this->fileParameters) > 0;
    }

    public function isArrayBody(): bool
    {
        return count($this->nestedBodyParameters) === 1
            && array_keys($this->nestedBodyParameters)[0] === '[]';
    }

    public function isGet(): bool
    {
        return in_array('GET', $this->httpMethods);
    }

    public function isAuthed(): bool
    {
        return $this->metadata->authenticated;
    }

    public function hasJsonBody(): bool
    {
        if ($this->hasFiles() || empty($this->nestedBodyParameters)) {
            return false;
        }

        $contentType = data_get($this->headers, 'Content-Type', data_get($this->headers, 'content-type', ''));

        return str_contains($contentType, 'json');
    }

    public function getSampleBody()
    {
        return WritingUtils::getSampleBody($this->nestedBodyParameters);
    }

    public function hasHeadersOrQueryOrBodyParams(): bool
    {
        return ! empty($this->headers)
            || ! empty($this->cleanQueryParameters)
            || ! empty($this->cleanBodyParameters);
    }

    public static function splitIntoFileAndRegularParameters(array $parameters): array
    {
        $files = [];
        $regularParameters = [];
        foreach ($parameters as $name => $example) {
            if ($example instanceof UploadedFile) {
                $files[$name] = $example;
            } elseif (is_array($example) && ! empty($example)) {
                [$subFiles, $subRegulars] = static::splitIntoFileAndRegularParameters($example);
                foreach ($subFiles as $subName => $subExample) {
                    $files[$name][$subName] = $subExample;
                }
                foreach ($subRegulars as $subName => $subExample) {
                    $regularParameters[$name][$subName] = $subExample;
                }
            } else {
                $regularParameters[$name] = $example;
            }
        }

        return [$files, $regularParameters];
    }
}
