<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Extracting;

use Hypervel\Routing\Router;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\Extractor;
use Ipsocode\Scribe\Tests\DatabaseTestCase;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Http\Controllers\InheritedDocsController;

/**
 * `inheritedDocsOverrides()` lets each child of a base controller declare what
 * differs from the docblocks they share. It runs after each extraction stage
 * and merges according to what the stage holds (a collection of responses, a
 * map of parameter DTOs, or a plain array), so each stage is tested.
 */
class InheritedDocsTest extends DatabaseTestCase
{
    protected function defineRoutes(Router $router): void
    {
        $router->post('api/inherited/listed', [InheritedDocsController::class, 'listed'])
            ->name('inherited.listed');
        $router->post('api/inherited/computed', [InheritedDocsController::class, 'computed'])
            ->name('inherited.computed');
        $router->post('api/inherited/several', [InheritedDocsController::class, 'several'])
            ->name('inherited.several');
    }

    private function extract(string $routeName): ExtractedEndpointData
    {
        return (new Extractor(new DocumentationConfig(config('scribe'))))
            ->processRoute($this->workbenchRoute($routeName));
    }

    #[Test]
    public function valuesDeclaredOnTheBaseControllerAreMergedIn(): void
    {
        $endpoint = $this->extract('inherited.listed');

        $this->assertSame('from the base', $endpoint->headers['X-Inherited']);

        $query = $endpoint->queryParameters['inherited'];
        $this->assertSame('Declared on the base controller.', $query->description);
        $this->assertTrue($query->required);
        // Merged as a real Parameter, so the clean values the response call and
        // the writers read are there too.
        $this->assertSame('yes', $endpoint->cleanQueryParameters['inherited']);

        $this->assertSame('boolean', $endpoint->responseFields['ok']->type);

        $responses = $endpoint->responses->toArray();
        $this->assertCount(1, $responses);
        $this->assertSame(201, $responses[0]['status']);
    }

    #[Test]
    public function callbacksOnTheBaseControllerAreGivenTheEndpoint(): void
    {
        $endpoint = $this->extract('inherited.computed');

        // The callback form replaces the stage rather than merging into it, and
        // is handed the endpoint so it can work from what was extracted.
        // Plus the JSON default the inherited body parameter brings with it.
        $this->assertSame(
            ['X-Endpoint' => 'api/inherited/computed', 'Content-Type' => 'application/json'],
            $endpoint->headers,
        );

        $this->assertSame('Worked out at extraction time.', $endpoint->bodyParameters['computed']->description);
        $this->assertSame('boolean', $endpoint->responseFields['ok']->type);

        $responses = $endpoint->responses->toArray();
        $this->assertCount(1, $responses);
        $this->assertSame(202, $responses[0]['status']);
    }

    #[Test]
    public function inheritedBodyParametersReachTheCleanParametersAndTheExampleRequest(): void
    {
        $endpoint = $this->extract('inherited.several');

        $this->assertSame(['title' => 'Hello'], $endpoint->cleanBodyParameters);
        $this->assertSame('application/json', $endpoint->headers['Content-Type']);
    }

    #[Test]
    public function severalInheritedResponsesAreEachDocumentedOnceAndSortedByStatus(): void
    {
        $statuses = array_column($this->extract('inherited.several')->responses->toArray(), 'status');

        $this->assertSame([200, 201], $statuses);
    }
}
