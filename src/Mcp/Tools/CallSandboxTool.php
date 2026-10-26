<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Mcp\Tools;

use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\JsonSchema\Types\Type;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server\Attributes\Description;
use Hypervel\Mcp\Server\Attributes\Name;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Hypervel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Ipsocode\Scribe\Mcp\Concerns\AnswersFromSpec;
use Ipsocode\Scribe\Mcp\OpenApiDocument;

#[Name('call-sandbox')]
#[Description('Try an endpoint without calling the API: check the request you built against the endpoint\'s parameters, and get back the response the documentation shows for it. Give its operationId and the request, and a status to get that response.')]
#[IsReadOnly]
#[IsIdempotent]
class CallSandboxTool extends Tool
{
    use AnswersFromSpec;

    public function __construct(protected OpenApiDocument $document)
    {
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

            return $this->mockResponse($operation, $arguments['status'] ?? null);
        });
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
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
