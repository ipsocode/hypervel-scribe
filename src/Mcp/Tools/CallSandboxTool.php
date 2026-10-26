<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Mcp\Tools;

use Closure;
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
use Ipsocode\Scribe\Mcp\Concerns\AnswersFromSpec;
use Ipsocode\Scribe\Mcp\OpenApiDocument;
use Psr\Http\Message\StreamInterface;

#[Name('call-sandbox')]
#[Description('Try an endpoint before you write client code: check the request you built against the endpoint\'s parameters, and get back the response the sandbox gives for it, which is either the example the documentation shows or a live sandbox\'s answer. Give its operationId and the request.')]
#[IsReadOnly]
#[IsIdempotent]
class CallSandboxTool extends Tool
{
    use AnswersFromSpec;

    /**
     * The request headers the caller cannot set: the host is the sandbox's, and the credentials come from the token.
     *
     * @var array<int, string>
     */
    protected const array RESERVED_HEADERS = ['authorization', 'content-length', 'host', 'proxy-authorization'];

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
     * Get the server the `http` mode calls: the sandbox's, then the Try It Out URL, then the displayed one.
     */
    public function baseUrl(): string
    {
        return mb_rtrim(
            $this->config->get('scribe.mcp.sandbox.base_url')
                ?: $this->config->get('scribe.try_it_out.base_url')
                ?: $this->config->get('scribe.base_url')
                ?: $this->config->string('app.url'),
            '/',
        );
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
            'headers' => ['nullable', 'array'],
            'body' => ['nullable', 'array'],
            'status' => ['nullable', 'integer'],
            'token' => ['nullable', 'string'],
            'pathParams.*' => [fn (string $attribute, mixed $value, Closure $fail) => match (true) {
                ! is_scalar($value) => $fail('The :attribute must be a string or a number.'),
                in_array((string) $value, ['.', '..'], true) => $fail('The :attribute cannot be a dot segment.'),
                default => true,
            }],
        ]);

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
        $missing = [];

        foreach ($operation['parameters'] ?? [] as $parameter) {
            $input = $inputs[$parameter['in']] ?? null;
            $required = $parameter['in'] === 'path' || ($parameter['required'] ?? false);

            if ($input !== null && $required && ! isset($arguments[$input][$parameter['name']])) {
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

        if ($status === null || ! isset($responses[$status])) {
            return Response::error('The endpoint documents no response for ' . ($status ?? 'a 2xx status') . '. Describe it to see its responses.');
        }

        foreach ($responses[$status]['content'] ?? [] as $media) {
            $example = array_key_exists('example', $media)
                ? $media['example']
                : (array_values($media['examples'] ?? [])[0]['value'] ?? null);

            if ($example !== null) {
                $json = (string) json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                return Response::make(Response::text($json))
                    ->withStructuredContent(['status' => $status, 'example' => $json]);
            }
        }

        return Response::error("The endpoint documents no example for {$status}. Describe it to see its responses.");
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

        $headers = array_filter(
            $arguments['headers'] ?? [],
            fn (string $name): bool => ! in_array(mb_strtolower($name), self::RESERVED_HEADERS, true),
            ARRAY_FILTER_USE_KEY,
        );

        if (($authorization = $this->authorization($arguments['token'] ?? null)) !== null) {
            $headers['Authorization'] = $authorization;
        }

        try {
            $response = Http::withHeaders($headers)
                ->withQueryParameters($arguments['query'] ?? [])
                ->timeout($this->config->float('scribe.mcp.sandbox.timeout'))
                ->withoutRedirecting()
                ->withOptions(['stream' => true])
                ->send(
                    $method,
                    $this->baseUrl() . $this->expandPath($operation['path'], $arguments['pathParams'] ?? []),
                    isset($arguments['body']) ? ['json' => $arguments['body']] : [],
                );
        } catch (ConnectionException $exception) {
            return Response::error('The sandbox could not be reached: ' . $exception->getMessage());
        }

        $limit = $this->config->integer('scribe.mcp.sandbox.max_response_bytes');
        $body = $this->readUpTo($response->toPsrResponse()->getBody(), $limit + 1);
        $truncated = mb_strlen($body, '8bit') > $limit;

        if ($truncated) {
            $body = mb_strcut($body, 0, $limit);
        }

        return Response::make(Response::text($truncated ? "{$body}\n[Truncated at {$limit} bytes.]" : $body))
            ->withStructuredContent(['status' => $response->status(), 'body' => $body, 'truncated' => $truncated]);
    }

    /**
     * Return the Authorization header of the request to this server, or else the token given as input.
     *
     * It is read on every call and kept nowhere, since the server serves many customers at once.
     */
    protected function authorization(?string $token): ?string
    {
        $header = RequestContext::getOrNull()?->header('Authorization');

        if (is_string($header) && $header !== '') {
            return $header;
        }

        return $token === null ? null : "Bearer {$token}";
    }

    /**
     * Fill the path template's placeholders, encoded so that a value cannot change the host or the path; a dot segment is rejected by the input rules, since encoding leaves it as it is.
     *
     * @param array<string, bool|float|int|string> $parameters
     */
    protected function expandPath(string $path, array $parameters): string
    {
        return (string) preg_replace_callback(
            '/\{(\w+)\}/',
            fn (array $match): string => rawurlencode((string) $parameters[$match[1]]),
            $path,
        );
    }

    /**
     * Read at most the given number of bytes from the stream.
     */
    protected function readUpTo(StreamInterface $stream, int $bytes): string
    {
        $contents = '';

        while (! $stream->eof() && strlen($contents) < $bytes) {
            $contents .= $stream->read($bytes - strlen($contents));
        }

        return $contents;
    }

    /**
     * Return the first 2xx status the operation documents, if any.
     *
     * @param array<int, int|string> $statuses
     */
    protected function firstSuccess(array $statuses): ?int
    {
        foreach ($statuses as $status) {
            if ($status >= 200 && $status < 300) {
                return (int) $status;
            }
        }

        return null;
    }
}
