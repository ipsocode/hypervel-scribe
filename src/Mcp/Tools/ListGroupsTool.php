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

#[Name('list-groups')]
#[Description('List the groups (tags) the API\'s endpoints are organized in, with a description and the number of endpoints in each.')]
#[IsReadOnly]
#[IsIdempotent]
class ListGroupsTool extends Tool
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
        return $this->answerFromSpec(function (): ResponseFactory {
            $counts = [];

            foreach ($this->document->operations() as $operation) {
                foreach ($operation['tags'] as $tag) {
                    $counts[$tag] = ($counts[$tag] ?? 0) + 1;
                }
            }

            $groups = [];

            foreach ($this->document->tags() as $tag) {
                $groups[] = [
                    'name' => $tag['name'],
                    'description' => $tag['description'] ?? '',
                    'endpoints' => $counts[$tag['name']] ?? 0,
                ];
            }

            return Response::structured(['groups' => $groups]);
        });
    }

    /**
     * Get the tool's output schema.
     *
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'groups' => $schema->array()
                ->items($schema->object([
                    'name' => $schema->string()->required(),
                    'description' => $schema->string()->required(),
                    'endpoints' => $schema->integer()->required(),
                ]))
                ->required(),
        ];
    }
}
