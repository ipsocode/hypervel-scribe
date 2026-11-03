<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Mcp\Resources;

use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server\Attributes\Description;
use Hypervel\Mcp\Server\Attributes\MimeType;
use Hypervel\Mcp\Server\Attributes\Name;
use Hypervel\Mcp\Server\Attributes\Uri;
use Hypervel\Mcp\Server\Resource;
use Ipsocode\Scribe\Mcp\Concerns\AnswersFromSpec;
use Ipsocode\Scribe\Mcp\OpenApiDocument;

#[Name('openapi-spec')]
#[Uri('scribe://openapi.yaml')]
#[MimeType('application/yaml')]
#[Description('The whole OpenAPI spec of the API, as generated. Load it when you need every endpoint at once.')]
class OpenApiSpecResource extends Resource
{
    use AnswersFromSpec;

    public function __construct(protected OpenApiDocument $document)
    {
    }

    /**
     * Handle the resource request.
     */
    public function handle(): Response|ResponseFactory
    {
        return $this->answerFromSpec(fn (): Response => Response::text($this->document->specContents()));
    }
}
