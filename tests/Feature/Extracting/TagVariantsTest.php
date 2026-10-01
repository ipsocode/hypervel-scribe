<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Extracting;

use Hypervel\Routing\Router;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\Extractor;
use Ipsocode\Scribe\Tests\DatabaseTestCase;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Http\Controllers\ClassLevelTagsController;

/**
 * `@queryParam name type required deprecated description` — every part after
 * the name is optional, and the parser has to work out which of them is
 * actually present. Getting it wrong turns a description into a type, or a
 * required parameter into an optional one.
 */
class TagVariantsTest extends DatabaseTestCase
{
    protected function defineRoutes(Router $router): void
    {
        $router->get('api/tags/class-level', [ClassLevelTagsController::class, 'inherits'])
            ->name('tags.classLevel');
        $router->get('api/tags/class-level-override', [ClassLevelTagsController::class, 'overrides'])
            ->name('tags.classLevelOverride');
    }

    private function extract(string $routeName): ExtractedEndpointData
    {
        return (new Extractor(new DocumentationConfig(config('scribe'))))
            ->processRoute($this->workbenchRoute($routeName));
    }

    #[Test]
    public function aNameOnItsOwnDefaultsToAnOptionalString(): void
    {
        $param = $this->extract('tags.bare')->queryParameters['bare'];

        $this->assertSame('string', $param->type);
        $this->assertFalse($param->required);
        $this->assertSame('', $param->description);
    }

    #[Test]
    public function anUntypedParameterIsAStringUnlessItsDescriptionSuggestsANumber(): void
    {
        $params = $this->extract('tags.described')->queryParameters;

        $this->assertSame('string', $params['text']->type);
        $this->assertSame('The text to search for.', $params['text']->description);
        // "page" and "count" in the description are read as numeric hints.
        $this->assertSame('integer', $params['page']->type);
        $this->assertSame('integer', $params['item_count']->type);
    }

    #[Test]
    public function aTypeWithNoDescriptionIsReadAsAType(): void
    {
        $param = $this->extract('tags.typed')->queryParameters['user_id'];

        $this->assertSame('integer', $param->type);
        $this->assertSame('', $param->description);
    }

    #[Test]
    public function requiredAndDeprecatedMarkersAreReadWithOrWithoutAType(): void
    {
        $params = $this->extract('tags.markers')->queryParameters;

        // Marker alone, no type and no description.
        $this->assertTrue($params['a']->required);
        $this->assertSame('string', $params['a']->type);
        $this->assertTrue($params['b']->deprecated);

        // Type, marker and description together.
        $this->assertTrue($params['c']->required);
        $this->assertSame('The c.', $params['c']->description);
        $this->assertTrue($params['d']->deprecated);
        $this->assertSame('Use `c` instead.', $params['d']->description);

        // Marker and description but no type.
        $this->assertTrue($params['e']->required);
        $this->assertSame('The e.', $params['e']->description);
        $this->assertTrue($params['f']->deprecated);
        $this->assertSame('The f.', $params['f']->description);
        $this->assertSame('string', $params['f']->type);
    }

    #[Test]
    public function aWordThatIsNotATypeStaysPartOfTheDescription(): void
    {
        $param = $this->extract('tags.notAType')->queryParameters['colour'];

        $this->assertSame('string', $param->type);
        $this->assertSame('Blue or green.', $param->description);
    }

    #[Test]
    public function noExampleSuppressesTheGeneratedExample(): void
    {
        $param = $this->extract('tags.noExample')->queryParameters['token'];

        $this->assertNull($param->example);
        $this->assertSame('The token.', $param->description);
    }

    #[Test]
    public function responseFieldTypesAreInferredFromTheResponseBody(): void
    {
        $fields = $this->extract('tags.fields')->responseFields;

        // Read through the ApiResource-style `data` envelope.
        $this->assertSame('integer', $fields['id']->type);
        $this->assertSame('string', $fields['name']->type);
        $this->assertSame('object', $fields['nested']->type);
    }

    #[Test]
    public function aResponseFieldTheBodyDoesNotContainGetsNoType(): void
    {
        $this->assertSame('', $this->extract('tags.ghostField')->responseFields['ghost']->type);
    }

    #[Test]
    public function responseFieldTypesAreInferredFromAListBody(): void
    {
        $this->assertSame('integer', $this->extract('tags.listFields')->responseFields['id']->type);
    }

    #[Test]
    public function urlParamsAreReadThroughTheSameShapes(): void
    {
        $params = $this->extract('tags.urlParams')->urlParameters;

        // Name only.
        $this->assertSame('string', $params['bare']->type);
        $this->assertFalse($params['bare']->required);
        $this->assertSame('', $params['bare']->description);

        // Marker alone, no type and no description.
        $this->assertTrue($params['marked']->required);
        $this->assertSame('string', $params['marked']->type);
        $this->assertSame('', $params['marked']->description);

        // Type alone, which the parser has to tell apart from a description.
        $this->assertSame('integer', $params['typed']->type);
        $this->assertSame('', $params['typed']->description);

        // Untyped, but the description reads as numeric.
        $this->assertSame('integer', $params['page']->type);
        $this->assertSame('The page number.', $params['page']->description);
    }

    #[Test]
    public function bodyParamsAreReadThroughTheSameShapesPlusDeprecated(): void
    {
        $params = $this->extract('tags.bodyParams')->bodyParameters;

        // Name and type only.
        $this->assertSame('string', $params['bare']->type);
        $this->assertFalse($params['bare']->required);
        $this->assertFalse($params['bare']->deprecated);
        $this->assertSame('', $params['bare']->description);

        // Marker with a type but no description.
        $this->assertTrue($params['marked']->required);
        $this->assertSame('', $params['marked']->description);

        $this->assertTrue($params['legacy']->deprecated);
        $this->assertSame('', $params['legacy']->description);
    }

    #[Test]
    public function aTagOnTheControllerAppliesToEveryEndpointInIt(): void
    {
        $endpoint = $this->extract('tags.classLevel');

        $this->assertSame('acme', $endpoint->queryParameters['tenant']->example);
        $this->assertSame('v2', $endpoint->queryParameters['api_version']->example);
        $this->assertArrayHasKey('X-Tenant', $endpoint->headers);
    }

    #[Test]
    public function aHeaderTagWithNoExampleGetsAGeneratedOne(): void
    {
        // The name alone is enough to document the header; the value still has
        // to be something a reader can copy into a request.
        $this->assertNotEmpty($this->extract('tags.classLevel')->headers['X-Tenant']);
    }

    #[Test]
    public function aTagOnTheMethodWinsOverTheControllers(): void
    {
        $params = $this->extract('tags.classLevelOverride')->queryParameters;

        $this->assertSame('v3', $params['api_version']->example);
        // The one the method says nothing about is still inherited.
        $this->assertSame('acme', $params['tenant']->example);
    }

    #[Test]
    public function anExplicitNullExampleIsKeptRatherThanGenerated(): void
    {
        $this->assertNull($this->extract('tags.classLevelOverride')->queryParameters['nothing']->example);
    }
}
