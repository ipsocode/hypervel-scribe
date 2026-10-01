<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Extracting;

use Exception;
use Hypervel\Routing\Router;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\Extractor;
use Ipsocode\Scribe\Tests\DatabaseTestCase;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Http\Controllers\TransformerController;

/**
 * Fractal transformers are the second way an application can describe its
 * responses, and they run through an entirely separate strategy from API
 * resources — a different tag family, a different instantiation path and a
 * different serializer.
 */
class TransformerResponsesTest extends DatabaseTestCase
{
    /**
     * The two endpoints that document a transformer with no `@transformerModel`.
     *
     * Kept out of `workbench/routes/api.php`: one of them is meant to fail, and
     * a route that throws during extraction would fail every generate-command
     * test along with it.
     */
    protected function defineRoutes(Router $router): void
    {
        $router->get('api/transformed-inferred', [TransformerController::class, 'inferredModel'])
            ->name('transformed.inferred');
        $router->get('api/transformed-undetectable', [TransformerController::class, 'undetectableModel'])
            ->name('transformed.undetectable');
        $router->get('api/transformed-attributed-single', [TransformerController::class, 'attributedSingle'])
            ->name('transformed.attributedSingle');
    }

    private function extract(string $routeName): ExtractedEndpointData
    {
        return (new Extractor(new DocumentationConfig(config('scribe'))))
            ->processRoute($this->workbenchRoute($routeName));
    }

    private function body(string $routeName, int $status = 200): array
    {
        $responses = collect($this->extract($routeName)->responses->toArray())->keyBy('status');

        $this->assertArrayHasKey($status, $responses->all());

        return json_decode($responses[$status]['content'], true);
    }

    #[Test]
    public function aSingleTransformedItemIsRendered(): void
    {
        $body = $this->body('transformed.show');

        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('title', $body['data']);
        // The transformer's own shape, not the model's.
        $this->assertArrayNotHasKey('body', $body['data']);
    }

    #[Test]
    public function aTransformedCollectionAppliesItsFactoryStates(): void
    {
        $body = $this->body('transformed.index');

        $this->assertCount(2, $body['data']);
        // `states=published` is applied to the example model's factory.
        $this->assertTrue($body['data'][0]['published']);
    }

    #[Test]
    public function aTransformedCollectionHonoursItsResourceKey(): void
    {
        // Fractal's default DataArraySerializer always emits `data` and ignores
        // the resource key; ArraySerializer is one that uses it, which is the
        // only way to see the key actually being passed through.
        config(['scribe.fractal.serializer' => \League\Fractal\Serializer\ArraySerializer::class]);

        $this->assertArrayHasKey('posts', $this->body('transformed.index'));
    }

    #[Test]
    public function aTransformedCollectionCanBePaginatedAndGivenAStatus(): void
    {
        // `@transformerCollection 201 ...` sets the status; the paginator tag
        // adds Fractal's own pagination metadata.
        $body = $this->body('transformed.paginated', 201);

        $this->assertArrayHasKey('meta', $body);
        $this->assertSame(1, $body['meta']['pagination']['per_page']);
        $this->assertCount(1, $body['data']);
    }

    #[Test]
    public function theTransformerAttributeCarriesEverythingTheTagsDo(): void
    {
        // Same strategy, second spelling. The tags scatter their arguments over
        // three docblock lines of free text; the attribute names each one, and
        // both have to end up at the same place.
        $body = $this->body('transformed.attributed', 201);

        $this->assertCount(1, $body['data']);
        $this->assertTrue($body['data'][0]['published']);
        $this->assertSame(1, $body['meta']['pagination']['per_page']);

        $response = collect($this->extract('transformed.attributed')->responses->toArray())->firstWhere('status', 201);
        $this->assertSame('The transformed posts', $response['description']);
    }

    #[Test]
    public function aConfiguredFractalSerializerChangesTheOutputShape(): void
    {
        // The ArraySerializer drops Fractal's `data` envelope, which is exactly
        // the sort of application-wide choice the docs have to reflect.
        config(['scribe.fractal.serializer' => \League\Fractal\Serializer\ArraySerializer::class]);

        $body = $this->body('transformed.show');

        $this->assertArrayNotHasKey('data', $body);
        $this->assertArrayHasKey('title', $body);
    }

    #[Test]
    public function theModelIsInferredFromTheTransformMethodWhenNoTagNamesIt(): void
    {
        // No `@transformerModel`, but PostTransformer::transform() takes a Post
        // — which is enough to build one.
        $body = $this->body('transformed.inferred');

        $this->assertArrayHasKey('title', $body['data']);
    }

    #[Test]
    public function aTransformerWithNothingToInferFromIsReported(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Couldn't detect a transformer model from your doc block");

        $this->extract('transformed.undetectable');
    }

    #[Test]
    public function aTransformerAttributeWithNoPaginatorReturnsASingleResource(): void
    {
        $body = $this->body('transformed.attributedSingle');

        // One item under `data`, and no `meta` — a paginated one carries both.
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('title', $body['data']);
        $this->assertArrayNotHasKey('meta', $body);
    }
}
