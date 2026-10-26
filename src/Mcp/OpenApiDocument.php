<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Mcp;

use Closure;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Support\Facades\Storage;
use Ipsocode\Scribe\Exceptions\CouldntReadOpenApiSpec;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use Ipsocode\Scribe\Tools\PathConfig;
use Ipsocode\Scribe\Tools\Utils;
use Symfony\Component\Yaml\Yaml;

/**
 * The OpenAPI spec `scribe:generate` wrote, read back for the MCP tools.
 *
 * The parsed spec is worker-owned and read-only. It is keyed by the file's
 * modification time, so a `scribe:generate` run on a live worker is picked up
 * on the next call without a restart.
 */
class OpenApiDocument
{
    /**
     * The keys of a path item that hold an operation.
     *
     * @var string[]
     */
    public const array METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /**
     * @var array<string, mixed>
     */
    protected array $spec = [];

    protected ?string $loadedPath = null;

    protected int $loadedAt = 0;

    public function __construct(protected Repository $config)
    {
    }

    /**
     * List every operation, without its parameters, request body or responses.
     *
     * @return array<int, array{id: string, method: string, path: string, summary: string, description: string, tags: array<int, string>, deprecated: bool}>
     */
    public function operations(): array
    {
        $operations = [];

        foreach ($this->load()['paths'] ?? [] as $path => $pathItem) {
            foreach (self::METHODS as $method) {
                if (isset($pathItem[$method])) {
                    $operations[] = $this->summarize($path, $method, $pathItem[$method]);
                }
            }
        }

        return $operations;
    }

    /**
     * Return one operation in full, with its path-level parameters merged in and
     * every `$ref` resolved, or null if the spec has no such operation.
     *
     * Give its `operationId`, or its HTTP method and path.
     *
     * @return null|array<string, mixed>
     */
    public function operation(string $idOrMethod, ?string $path = null): ?array
    {
        foreach ($this->load()['paths'] ?? [] as $candidatePath => $pathItem) {
            foreach (self::METHODS as $method) {
                $operation = $pathItem[$method] ?? null;

                if ($operation === null) {
                    continue;
                }

                $matches = $path === null
                    ? ($operation['operationId'] ?? null) === $idOrMethod
                    : $method === mb_strtolower($idOrMethod) && $candidatePath === $path;

                if ($matches) {
                    return $this->resolve($this->withPathParameters($pathItem, $operation) + [
                        'method' => $method,
                        'path' => $candidatePath,
                    ]);
                }
            }
        }

        return null;
    }

    /**
     * Return the tags the API declares.
     *
     * @return array<int, array{name: string, description?: string}>
     */
    public function tags(): array
    {
        return $this->load()['tags'] ?? [];
    }

    /**
     * Return the security schemes the API declares, and the requirement applied to every operation.
     *
     * @return array{schemes: array<string, mixed>, requirements: array<int, array<string, array<int, string>>>}
     */
    public function security(): array
    {
        $spec = $this->load();

        return [
            'schemes' => $this->resolve($spec['components']['securitySchemes'] ?? []),
            'requirements' => $spec['security'] ?? [],
        ];
    }

    /**
     * Return the servers the API declares.
     *
     * @return array<int, array{url: string, description?: string}>
     */
    public function servers(): array
    {
        return $this->load()['servers'] ?? [];
    }

    /**
     * Return the generated spec file as written.
     */
    public function specContents(): string
    {
        return $this->contents($this->outputFile('openapi.yaml'), CouldntReadOpenApiSpec::missing(...));
    }

    /**
     * Return the generated Postman collection file as written.
     */
    public function collectionContents(): string
    {
        return $this->contents($this->outputFile('collection.json'), CouldntReadOpenApiSpec::missingCollection(...));
    }

    /**
     * Return the parsed spec, parsing it again if the file has changed.
     *
     * @return array<string, mixed>
     */
    protected function load(): array
    {
        $path = $this->outputFile('openapi.yaml');

        if (! is_file($path)) {
            throw CouldntReadOpenApiSpec::missing($path);
        }

        // PHP caches the stat of the last file it looked at for the life of the worker.
        clearstatcache(true, $path);
        $modifiedAt = (int) filemtime($path);

        if ($path !== $this->loadedPath || $modifiedAt !== $this->loadedAt) {
            $spec = Yaml::parseFile($path);

            if (! is_array($spec)) {
                throw CouldntReadOpenApiSpec::unreadable($path);
            }

            // Assigned together, after the parse, so a reader never sees a half-loaded spec.
            [$this->spec, $this->loadedPath, $this->loadedAt] = [$spec, $path, $modifiedAt];
        }

        return $this->spec;
    }

    /**
     * Return where the writer put a generated file: the local disk for the
     * routed types, `static.output_path` for the static ones.
     */
    protected function outputFile(string $file): string
    {
        $documentation = new DocumentationConfig(['type' => $this->config->get('scribe.type')]);

        if ($documentation->outputRoutedThroughApp()) {
            return Storage::disk('local')->path((new PathConfig)->outputPath($file));
        }

        return Utils::resolvePath(mb_rtrim($this->config->string('scribe.static.output_path'), '/')) . '/' . $file;
    }

    /**
     * Return a generated file as written, or throw the exception `$missing` builds.
     *
     * @param Closure(string): CouldntReadOpenApiSpec $missing
     */
    protected function contents(string $path, Closure $missing): string
    {
        if (! is_file($path)) {
            throw $missing($path);
        }

        return (string) file_get_contents($path);
    }

    /**
     * Return the fields of an operation the search and describe tools show.
     *
     * @param array<string, mixed> $operation
     * @return array{id: string, method: string, path: string, summary: string, description: string, tags: array<int, string>, deprecated: bool}
     */
    protected function summarize(string $path, string $method, array $operation): array
    {
        return [
            'id' => $operation['operationId'],
            'method' => $method,
            'path' => $path,
            'summary' => $operation['summary'] ?? '',
            'description' => $operation['description'] ?? '',
            'tags' => $operation['tags'] ?? [],
            'deprecated' => $operation['deprecated'] ?? false,
        ];
    }

    /**
     * Add the parameters shared by the whole path item, unless the operation declares the same one.
     *
     * @param array<string, mixed> $pathItem
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    protected function withPathParameters(array $pathItem, array $operation): array
    {
        $parameters = [];

        foreach ([...($pathItem['parameters'] ?? []), ...($operation['parameters'] ?? [])] as $parameter) {
            $parameter = $this->resolve($parameter);
            $parameters[$parameter['in'] . ':' . $parameter['name']] = $parameter;
        }

        if ($parameters !== []) {
            $operation['parameters'] = array_values($parameters);
        }

        return $operation;
    }

    /**
     * Replace each local `$ref` under the value with what it points at.
     *
     * A reference that leads back to itself is left in place, so a recursive schema stays finite.
     *
     * @param array<int, string> $resolving the references being resolved above this value
     */
    protected function resolve(mixed $value, array $resolving = []): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $reference = $value['$ref'] ?? null;

        if (is_string($reference)) {
            if (in_array($reference, $resolving, true)) {
                return $value;
            }

            return $this->resolve($this->lookup($reference), [...$resolving, $reference]);
        }

        return array_map(fn (mixed $item) => $this->resolve($item, $resolving), $value);
    }

    protected function lookup(string $reference): mixed
    {
        if (! str_starts_with($reference, '#/')) {
            throw CouldntReadOpenApiSpec::unresolvedReference($reference);
        }

        $target = $this->spec;

        foreach (explode('/', mb_substr($reference, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if (! is_array($target) || ! array_key_exists($segment, $target)) {
                throw CouldntReadOpenApiSpec::unresolvedReference($reference);
            }

            $target = $target[$segment];
        }

        return $target;
    }
}
