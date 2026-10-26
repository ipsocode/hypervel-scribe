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

#[Name('search-endpoints')]
#[Description('Search the API\'s endpoints by words in their path, summary, description or group, and list the matches. Describe one to read its parameters, bodies and responses.')]
#[IsReadOnly]
#[IsIdempotent]
class SearchEndpointsTool extends Tool
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
            'query' => ['required', 'string'],
            'group' => ['nullable', 'string'],
            'method' => ['nullable', 'string'],
        ]);

        return $this->answerFromSpec(function () use ($arguments): ResponseFactory {
            $words = preg_split('/\s+/', mb_strtolower($arguments['query']), -1, PREG_SPLIT_NO_EMPTY);
            $endpoints = [];

            foreach ($this->document->operations() as $operation) {
                if (filled($arguments['group'] ?? null) && ! in_array($arguments['group'], $operation['tags'], true)) {
                    continue;
                }

                if (filled($arguments['method'] ?? null) && mb_strtolower($arguments['method']) !== $operation['method']) {
                    continue;
                }

                $haystack = mb_strtolower(implode(' ', [
                    $operation['id'], $operation['path'], $operation['summary'], $operation['description'], ...$operation['tags'],
                ]));

                foreach ($words as $word) {
                    if (! str_contains($haystack, $word)) {
                        continue 2;
                    }
                }

                $endpoints[] = [
                    'operationId' => $operation['id'],
                    'method' => $operation['method'],
                    'path' => $operation['path'],
                    'summary' => $operation['summary'],
                    'group' => $operation['tags'][0] ?? '',
                ];
            }

            return Response::structured(['endpoints' => $endpoints]);
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
            'query' => $schema->string()
                ->description('Words to look for. Every word must appear in the endpoint.')
                ->required(),
            'group' => $schema->string()
                ->description('Only list endpoints in this group (tag). Use list-groups to see the groups.'),
            'method' => $schema->string()
                ->description('Only list endpoints with this HTTP method, such as GET or POST.'),
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
            'endpoints' => $schema->array()
                ->items($schema->object([
                    'operationId' => $schema->string()->required(),
                    'method' => $schema->string()->required(),
                    'path' => $schema->string()->required(),
                    'summary' => $schema->string()->required(),
                    'group' => $schema->string()->required(),
                ]))
                ->required(),
        ];
    }
}
