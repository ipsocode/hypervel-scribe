<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Mcp\Resources;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server\Attributes\Description;
use Hypervel\Mcp\Server\Attributes\MimeType;
use Hypervel\Mcp\Server\Attributes\Name;
use Hypervel\Mcp\Server\Attributes\Uri;
use Hypervel\Mcp\Server\Resource;
use Ipsocode\Scribe\Mcp\Concerns\AnswersFromSpec;
use Ipsocode\Scribe\Mcp\OpenApiDocument;

#[Name('postman-collection')]
#[Uri('scribe://collection.json')]
#[MimeType('application/json')]
#[Description('The Postman collection of the API, as generated.')]
class PostmanCollectionResource extends Resource
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
        return $this->answerFromSpec(fn (): Response => Response::text($this->document->collectionContents()));
    }

    /**
     * Determine whether the resource is registered: only when Postman output is on.
     */
    public function shouldRegister(Repository $config): bool
    {
        return $config->boolean('scribe.postman.enabled');
    }
}
