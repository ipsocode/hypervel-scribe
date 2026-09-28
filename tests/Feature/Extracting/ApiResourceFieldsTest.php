<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Extracting;

use Hypervel\Routing\Router;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\Extractor;
use Ipsocode\Scribe\Tests\DatabaseTestCase;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;
use Workbench\App\Http\Controllers\ApiResourceFieldsController;

/**
 * A response's fields belong with the API resource that shapes it, so Scribe
 * reads them off the resource's `toArray()` rather than expecting every
 * controller method to repeat them. Tags and attributes are separate readers,
 * and a resource with a `$wrap` key nests everything it documents.
 */
class ApiResourceFieldsTest extends DatabaseTestCase
{
    protected function defineRoutes(Router $router): void
    {
        $router->get('api/resource-fields/tagged', [ApiResourceFieldsController::class, 'taggedFields'])
            ->name('resourceFields.tagged');
        $router->get('api/resource-fields/tagged-wrapped', [ApiResourceFieldsController::class, 'taggedWrappedFields'])
            ->name('resourceFields.taggedWrapped');
        $router->get('api/resource-fields/attributed', [ApiResourceFieldsController::class, 'attributedFields'])
            ->name('resourceFields.attributed');
        $router->get('api/resource-fields/attributed-wrapped', [ApiResourceFieldsController::class, 'attributedWrappedFields'])
            ->name('resourceFields.attributedWrapped');
        $router->get('api/resource-fields/no-model', [ApiResourceFieldsController::class, 'noModelToTransform'])
            ->name('resourceFields.noModel');
        $router->get('api/users/{user}/posts/{post}', [ApiResourceFieldsController::class, 'boundToOneOfTwoModels'])
            ->name('resourceFields.bound');
    }

    private function extract(string $routeName): ExtractedEndpointData
    {
        return (new Extractor(new DocumentationConfig(config('scribe'))))
            ->processRoute($this->workbenchRoute($routeName));
    }

    #[Test]
    public function responseFieldTagsOnTheResourceDocumentTheResponse(): void
    {
        $fields = $this->extract('resourceFields.tagged')->responseFields;

        $this->assertSame('integer', $fields['id']->type);
        $this->assertSame("The post's title.", $fields['title']->description);
    }

    #[Test]
    public function responseFieldTagsOnAWrappedResourceAreNestedUnderItsKey(): void
    {
        $fields = $this->extract('resourceFields.taggedWrapped')->responseFields;

        $this->assertArrayHasKey('data.id', $fields);
        $this->assertArrayNotHasKey('id', $fields);
    }

    #[Test]
    public function responseFieldAttributesOnTheResourceDocumentTheResponse(): void
    {
        $fields = $this->extract('resourceFields.attributed')->responseFields;

        $this->assertSame('The post body.', $fields['body']->description);
        $this->assertSame('boolean', $fields['published']->type);
    }

    #[Test]
    public function responseFieldAttributesOnAWrappedResourceAreNestedToo(): void
    {
        $this->assertArrayHasKey('data.title', $this->extract('resourceFields.attributedWrapped')->responseFields);
    }

    #[Test]
    public function aResourceAttributeWithNoModelStillProducesAResponse(): void
    {
        // Nothing to instantiate, so the resource renders from nothing — which
        // is still a documented response, not a failed endpoint. The user is
        // told, because an empty response body is rarely what they wanted.
        ConsoleOutputUtils::bootstrapOutput($output = new BufferedOutput);

        $responses = $this->extract('resourceFields.noModel')->responses->toArray();

        $this->assertCount(1, $responses);
        $this->assertJson($responses[0]['content']);
        $this->assertStringContainsString(
            "Couldn't detect an Eloquent API resource model",
            $output->fetch(),
        );
    }

    #[Test]
    public function theExampleModelsIdLandsOnTheParameterBoundToItsOwnModel(): void
    {
        // Two models are bound to this URL; only the one the resource is built
        // from should have its id written into the path.
        $endpoint = $this->extract('resourceFields.bound');

        $id = json_decode($endpoint->responses->toArray()[0]['content'], true)['id'];

        $this->assertSame($id, $endpoint->urlParameters['post_id']->example);
        $this->assertNotSame($id, $endpoint->urlParameters['user_id']->example);
    }
}
