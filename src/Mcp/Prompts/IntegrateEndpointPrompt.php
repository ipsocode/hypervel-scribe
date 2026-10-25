<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Mcp\Prompts;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server\Attributes\Description;
use Hypervel\Mcp\Server\Attributes\Name;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Prompts\Argument;
use Ipsocode\Scribe\Mcp\Concerns\AnswersFromSpec;
use Ipsocode\Scribe\Mcp\OpenApiDocument;

#[Name('integrate-endpoint')]
#[Description('Write a client call for one endpoint of the API, with its authentication and error handling.')]
class IntegrateEndpointPrompt extends Prompt
{
    use AnswersFromSpec;

    public function __construct(protected OpenApiDocument $document)
    {
    }

    /**
     * Handle the prompt request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $arguments = $request->validate([
            'operationId' => ['required', 'string'],
            'language' => ['required', 'string'],
        ]);

        return $this->answerFromSpec(function () use ($arguments): Response {
            $operation = $this->document->operation($arguments['operationId']);

            if ($operation === null) {
                return Response::error('No such endpoint. Use search-endpoints to find its operationId.');
            }

            $summary = $operation['summary'] ?? $arguments['operationId'];

            return Response::text(
                "Write a {$arguments['language']} client call for the \"{$summary}\" endpoint "
                . "({$operation['method']} {$operation['path']}, operationId {$arguments['operationId']}). "
                . 'Read it with describe-endpoint and get the authentication scheme and base URL with get-authentication first. '
                . 'Authenticate the request as the API requires, send every required parameter and body field, '
                . 'and handle each documented error response.'
            );
        });
    }

    /**
     * Get the prompt's arguments.
     *
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('operationId', 'The endpoint\'s operationId, as search-endpoints lists it.', required: true),
            new Argument('language', 'The programming language of the client code, such as PHP or TypeScript.', required: true),
        ];
    }
}
