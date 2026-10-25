<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Mcp\Tools;

use Hypervel\Contracts\Config\Repository;
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

#[Name('get-authentication')]
#[Description('Get how to authenticate against the API and which base URL to call: the security schemes, the servers, and any extra instructions.')]
#[IsReadOnly]
#[IsIdempotent]
class GetAuthenticationTool extends Tool
{
    use AnswersFromSpec;

    public function __construct(
        protected OpenApiDocument $document,
        protected Repository $config,
    ) {
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answerFromSpec(function (): ResponseFactory {
            $security = $this->document->security();

            return Response::structured([
                'schemes' => (object) $security['schemes'],
                'requirements' => $security['requirements'],
                'servers' => $this->document->servers(),
                'instructions' => $this->config->get('scribe.auth.extra_info') ?? '',
                'placeholder' => $this->config->get('scribe.auth.placeholder') ?? '',
            ]);
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
            'schemes' => $schema->object()->description('The security schemes, by name, as OpenAPI declares them.')->required(),
            'requirements' => $schema->array()->description('The schemes an operation needs unless it says otherwise.')->required(),
            'servers' => $schema->array()->description('The base URLs the API can be called at.')->required(),
            'instructions' => $schema->string()->description('How to get a token or key.')->required(),
            'placeholder' => $schema->string()->description('The placeholder the docs show where the credential goes.')->required(),
        ];
    }
}
