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
use Symfony\Component\Yaml\Yaml;

#[Name('describe-endpoint')]
#[Description('Read one endpoint in full: its parameters, request body and example, responses and examples, and security. Give its operationId, or its method and path. Write client code from this.')]
#[IsReadOnly]
#[IsIdempotent]
class DescribeEndpointTool extends Tool
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
            'operationId' => ['required_without_all:method,path', 'nullable', 'string'],
            'method' => ['required_with:path', 'nullable', 'string'],
            'path' => ['required_with:method', 'nullable', 'string'],
        ]);

        return $this->answerFromSpec(function () use ($arguments): Response|ResponseFactory {
            $operation = filled($arguments['operationId'] ?? null)
                ? $this->document->operation($arguments['operationId'])
                : $this->document->operation($arguments['method'], $arguments['path']);

            if ($operation === null) {
                return Response::error('No such endpoint. Use search-endpoints to find its operationId.');
            }

            return Response::make(Response::text(Yaml::dump($operation, 12, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE)))
                ->withStructuredContent(['operation' => $operation]);
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
                ->description('The endpoint\'s operationId, as search-endpoints lists it.'),
            'method' => $schema->string()
                ->description('The endpoint\'s HTTP method. Give it with path when you have no operationId.'),
            'path' => $schema->string()
                ->description('The endpoint\'s path, such as /api/users/{id}. Give it with method when you have no operationId.'),
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
            'operation' => $schema->object([
                'operationId' => $schema->string()->required(),
                'method' => $schema->string()->required(),
                'path' => $schema->string()->required(),
            ])->required(),
        ];
    }
}
