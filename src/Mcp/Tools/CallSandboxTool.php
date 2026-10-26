<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Mcp\Tools;

use Closure;
use GuzzleHttp\Exception\TransferException;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\JsonSchema\Types\Type;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server\Attributes\Description;
use Hypervel\Mcp\Server\Attributes\Name;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Hypervel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Json;
use Ipsocode\Scribe\Mcp\Concerns\AnswersFromSpec;
use Ipsocode\Scribe\Mcp\OpenApiDocument;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

#[Name('call-sandbox')]
#[Description('Try an endpoint before you write client code: check the request you built against the endpoint\'s parameters, and get back the response the sandbox gives for it, which is either the example the documentation shows or a live sandbox\'s answer. Give its operationId and the request.')]
#[IsReadOnly]
#[IsIdempotent]
class CallSandboxTool extends Tool
{
    use AnswersFromSpec;

    /**
     * The request headers the caller cannot set: the host is the sandbox's, the credentials come from the token, and
     * the method is the one the spec documents.
     *
     * @var array<int, string>
     */
    protected const array RESERVED_HEADERS = [
        'authorization',
        'content-length',
        'forwarded',
        'host',
        'proxy-authorization',
        'x-http-method',
        'x-http-method-override',
        'x-method-override',
    ];

    /**
     * The query and body parameter that overrides the request method.
     */
    protected const string METHOD_OVERRIDE = '_method';

    public function __construct(protected OpenApiDocument $document, protected Repository $config)
    {
    }

    /**
     * Get the tool's annotations, which warn the client when it calls a live sandbox.
     *
     * @return array<string, mixed>
     */
    public function annotations(): array
    {
        $annotations = parent::annotations();

        if (! $this->isLive()) {
            return $annotations;
        }

        return [
            ...$annotations,
            'readOnlyHint' => false,
            'idempotentHint' => false,
            'destructiveHint' => true,
            'openWorldHint' => true,
        ];
    }

    /**
     * Determine whether the tool is offered to clients.
     */
    public function shouldRegister(Repository $config): bool
    {
        return $config->boolean('scribe.mcp.sandbox.enabled');
    }

    /**
     * Get the server the `http` mode calls, which is the sandbox's own.
     */
    public function baseUrl(): string
    {
        return mb_rtrim($this->config->string('scribe.mcp.sandbox.base_url'), '/');
    }

    /**
     * Determine whether the tool sends requests to a sandbox instead of answering from the documentation.
     */
    public function isLive(): bool
    {
        return $this->config->string('scribe.mcp.sandbox.mode') === 'http';
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $arguments = $request->validate([
            'operationId' => ['required', 'string'],
            'pathParams' => ['nullable', 'array'],
            'query' => ['nullable', 'array'],
            'headers' => ['nullable', 'array', fn (string $attribute, mixed $value, Closure $fail) => $this->hasValidHeaderNames($value)
                ?: $fail('The :attribute must be named by HTTP header names.')],
            'headers.*' => ['string'],
            'body' => ['nullable', 'array'],
            'status' => ['nullable', 'integer:strict'],
            'token' => ['nullable', 'string'],
            'pathParams.*' => ['required', fn (string $attribute, mixed $value, Closure $fail) => match (true) {
                ! is_string($value) && ! is_int($value) && ! is_float($value) => $fail('The :attribute must be a string or a number.'),
                in_array((string) $value, ['.', '..'], true) => $fail('The :attribute cannot be a dot segment.'),
                str_contains((string) $value, '/') => $fail('The :attribute cannot contain a slash.'),
                default => true,
            }],
        ]);

        // MCP requests do not turn empty strings into null, and clients often fill optional inputs with one.
        $arguments = array_filter($arguments, fn (mixed $value): bool => $value !== '');

        return $this->answerFromSpec(function () use ($arguments): Response|ResponseFactory {
            $operation = $this->document->operation($arguments['operationId']);

            if ($operation === null) {
                return Response::error('No such endpoint. Use search-endpoints to find its operationId.');
            }

            $missing = $this->missing($operation, $arguments);

            if ($missing !== []) {
                return Response::error('The request is missing: ' . implode(', ', $missing) . '.');
            }

            return $this->isLive()
                ? $this->callSandbox($operation, $arguments)
                : $this->mockResponse($operation, $arguments['status'] ?? null);
        });
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $input = [
            'operationId' => $schema->string()
                ->description('The endpoint\'s operationId, as search-endpoints lists it.')
                ->required(),
            'pathParams' => $schema->object()
                ->description('The values for the path\'s {placeholders}, by name.'),
            'query' => $schema->object()
                ->description('The query parameters, by name.'),
            'headers' => $schema->object()
                ->description('The request headers, by name.'),
            'body' => $schema->object()
                ->description('The JSON request body.'),
        ];

        if ($this->isLive()) {
            return $input + [
                'token' => $schema->string()
                    ->description('Your API token for the sandbox, sent as a Bearer token. Needed only when the request to this server carries no Authorization header, as with a local server.'),
            ];
        }

        return $input + [
            'status' => $schema->integer()
                ->description('The response status to return. Defaults to the first 2xx the endpoint documents.'),
        ];
    }

    /**
     * Get the tool's output schema.
     *
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        if ($this->isLive()) {
            return [
                'status' => $schema->integer()->required(),
                'body' => $schema->string()->description('The sandbox\'s response body.')->required(),
                'truncated' => $schema->boolean()->description('Whether the body was cut off at the size limit.')->required(),
            ];
        }

        return [
            'status' => $schema->integer()->required(),
            'example' => $schema->string()->description('The documented example, as JSON.')->required(),
        ];
    }

    /**
     * List what the operation requires and the request leaves out.
     *
     * @param array<string, mixed> $operation
     * @param array<string, mixed> $arguments
     * @return array<int, string>
     */
    protected function missing(array $operation, array $arguments): array
    {
        $inputs = ['path' => 'pathParams', 'query' => 'query', 'header' => 'headers'];
        $headers = array_change_key_case($arguments['headers'] ?? []);
        $missing = [];

        foreach ($operation['parameters'] ?? [] as $parameter) {
            $input = $inputs[$parameter['in']] ?? null;
            $required = $parameter['in'] === 'path' || ($parameter['required'] ?? false);
            $given = $parameter['in'] === 'header'
                ? isset($headers[mb_strtolower($parameter['name'])])
                : isset($arguments[$input][$parameter['name']]);

            if ($input !== null && $required && ! $given) {
                $missing[] = "{$parameter['in']} parameter {$parameter['name']}";
            }
        }

        if (($operation['requestBody']['required'] ?? false) && ! isset($arguments['body'])) {
            $missing[] = 'the request body';
        }

        return $missing;
    }

    /**
     * Return the documented example for the status, or the first 2xx when none is given.
     *
     * @param array<string, mixed> $operation
     */
    protected function mockResponse(array $operation, ?int $status): Response|ResponseFactory
    {
        $responses = $operation['responses'] ?? [];

        $status ??= $this->firstSuccess(array_keys($responses));
        $response = $status === null ? null : ($responses[$status] ?? $responses[intdiv($status, 100) . 'XX'] ?? null);

        if ($response === null) {
            return Response::error('The endpoint documents no response for ' . ($status ?? 'a 2xx status') . '. Describe it to see its responses.');
        }

        if (($response['content'] ?? []) === []) {
            return Response::make(Response::text('The response has no body.'))
                ->withStructuredContent(['status' => $status, 'example' => '']);
        }

        foreach ($response['content'] as $media) {
            $example = $this->exampleOf($media);

            if ($example !== null) {
                $json = Json::encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                return Response::make(Response::text($json))
                    ->withStructuredContent(['status' => $status, 'example' => $json]);
            }
        }

        return Response::error("The endpoint documents no example for {$status}. Describe it to see its responses.");
    }

    /**
     * Return the example a media type documents, as the writer puts it in the schema or as a hand-written spec puts it beside it.
     *
     * @param array<string, mixed> $media
     */
    protected function exampleOf(array $media): mixed
    {
        $schema = $media['schema'] ?? [];

        $example = $media['example']
            ?? array_values($media['examples'] ?? [])[0]['value']
            ?? $schema['example']
            ?? $schema['examples'][0]
            ?? null;

        foreach ($schema['oneOf'] ?? [] as $alternative) {
            $example ??= $alternative['example'] ?? null;
        }

        return $example;
    }

    /**
     * Send the request to the sandbox, and return its response up to the size limit.
     *
     * @param array<string, mixed> $operation
     * @param array<string, mixed> $arguments
     */
    protected function callSandbox(array $operation, array $arguments): Response|ResponseFactory
    {
        $method = mb_strtoupper($operation['method']);

        if (! in_array($method, array_map(mb_strtoupper(...), $this->config->array('scribe.mcp.sandbox.methods')), true)) {
            return Response::error("The sandbox does not allow {$method} requests.");
        }

        if (array_key_exists(self::METHOD_OVERRIDE, $arguments['query'] ?? []) || array_key_exists(self::METHOD_OVERRIDE, $arguments['body'] ?? [])) {
            return Response::error('The request cannot override its method with `' . self::METHOD_OVERRIDE . '`.');
        }

        $headers = array_filter(
            $arguments['headers'] ?? [],
            fn (string $name): bool => ! in_array(mb_strtolower($name), self::RESERVED_HEADERS, true) && ! str_starts_with(mb_strtolower($name), 'x-forwarded-'),
            ARRAY_FILTER_USE_KEY,
        );

        $query = $arguments['query'] ?? [];
        $body = $arguments['body'] ?? null;

        [$headers, $query, $body] = $this->authenticate($headers, $query, $body, $arguments['token'] ?? null);

        $timeout = $this->config->float('scribe.mcp.sandbox.timeout');
        $limit = $this->config->integer('scribe.mcp.sandbox.max_response_bytes');

        try {
            $response = Http::withHeaders($headers)
                ->withQueryParameters($query)
                ->withUrlParameters($arguments['pathParams'] ?? [])
                ->timeout($timeout)
                ->withoutRedirecting()
                ->withOptions(['stream' => true, 'read_timeout' => $timeout])
                ->send($method, $this->baseUrl() . $operation['path'], $body === null ? [] : ['json' => $body]);

            $contents = $this->readUpTo($response->toPsrResponse()->getBody(), $limit + 1, $timeout);
        } catch (ConnectionException|TransferException|RuntimeException $exception) {
            return Response::error('The sandbox could not be reached: ' . $exception->getMessage());
        }

        $truncated = strlen($contents) > $limit;
        $contents = $truncated ? mb_strcut($contents, 0, $limit) : $contents;

        return Response::make(Response::text($truncated ? "{$contents}\n[Truncated at {$limit} bytes.]" : $contents))
            ->withStructuredContent(['status' => $response->status(), 'body' => $contents, 'truncated' => $truncated]);
    }

    /**
     * Add the caller's credential to the request: the token given as input, placed as `auth.in` says, or else the
     * Authorization header of the request to this server.
     *
     * It is read on every call and kept nowhere, since the server serves many customers at once.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     * @param null|array<string, mixed> $body
     * @return array{0: array<string, string>, 1: array<string, mixed>, 2: null|array<string, mixed>}
     */
    protected function authenticate(array $headers, array $query, ?array $body, ?string $token): array
    {
        if ($token !== null) {
            $in = $this->config->boolean('scribe.auth.enabled') ? $this->config->string('scribe.auth.in') : 'bearer';
            $name = $this->config->string('scribe.auth.name');

            return match ($in) {
                'query' => [$headers, [...$query, $name => $token], $body],
                'body' => [$headers, $query, [...($body ?? []), $name => $token]],
                'header' => [[...$headers, $name => $token], $query, $body],
                default => [[...$headers, 'Authorization' => ucfirst($in) . " {$token}"], $query, $body],
            };
        }

        $header = RequestContext::getOrNull()?->header('Authorization');

        if (is_string($header) && $header !== '') {
            $headers['Authorization'] = $header;
        }

        return [$headers, $query, $body];
    }

    /**
     * Determine whether every key is an HTTP header name.
     */
    protected function hasValidHeaderNames(mixed $headers): bool
    {
        foreach (array_keys($headers) as $name) {
            if (! is_string($name) || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $name) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Read at most the given number of bytes from the stream, within the given number of seconds.
     *
     * @throws RuntimeException When the stream does not end in time
     */
    protected function readUpTo(StreamInterface $stream, int $bytes, float $timeout): string
    {
        $deadline = hrtime(true) + (int) ($timeout * 1_000_000_000);
        $contents = '';

        while (! $stream->eof() && strlen($contents) < $bytes) {
            if (hrtime(true) > $deadline) {
                throw new RuntimeException("The sandbox did not finish responding within {$timeout} seconds.");
            }

            $contents .= $stream->read($bytes - strlen($contents));
        }

        return $contents;
    }

    /**
     * Return the first 2xx status the operation documents, if any; the range `2XX` stands for 200.
     *
     * @param array<int, int|string> $statuses
     */
    protected function firstSuccess(array $statuses): ?int
    {
        foreach ($statuses as $status) {
            if (is_int($status) && $status >= 200 && $status < 300) {
                return $status;
            }

            if (mb_strtoupper((string) $status) === '2XX') {
                return 200;
            }
        }

        return null;
    }
}
