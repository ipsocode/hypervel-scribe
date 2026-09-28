<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Extracting;

use Hypervel\Routing\Router;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\Extractor;
use Ipsocode\Scribe\Scribe;
use Ipsocode\Scribe\Tests\DatabaseTestCase;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use Ipsocode\Scribe\Tools\Globals;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;
use Workbench\App\Http\Controllers\FormRequestVariantsController;
use Workbench\App\Http\Requests\SearchPostsRequest;

/**
 * A FormRequest is the richest thing Scribe reads parameters out of, and the
 * body and query strategies share one base class to do it — so which of the two
 * claims a given request, and where it looks for descriptions and examples, is
 * decided by details of the class itself.
 *
 * StorePostRequest covers the fully-populated case; every endpoint here is one
 * of the ways a real application's request differs from it.
 */
class FormRequestVariantsTest extends DatabaseTestCase
{
    protected function defineRoutes(Router $router): void
    {
        $router->post('api/form-requests/search', [FormRequestVariantsController::class, 'search'])
            ->name('formRequests.search');
        $router->post('api/form-requests/redeem', [FormRequestVariantsController::class, 'redeem'])
            ->name('formRequests.redeem');
        $router->post('api/form-requests/report', [FormRequestVariantsController::class, 'report'])
            ->name('formRequests.report');
        $router->post('api/form-requests/partial', [FormRequestVariantsController::class, 'partial'])
            ->name('formRequests.partial');
        $router->post('api/form-requests/open', [FormRequestVariantsController::class, 'open'])
            ->name('formRequests.open');
        $router->post('api/form-requests/annotated', [FormRequestVariantsController::class, 'annotated'])
            ->name('formRequests.annotated');
        $router->post('api/form-requests/undocumented', [FormRequestVariantsController::class, 'undocumented'])
            ->name('formRequests.undocumented');
    }

    private function extract(string $routeName): ExtractedEndpointData
    {
        return (new Extractor(new DocumentationConfig(config('scribe'))))
            ->processRoute($this->workbenchRoute($routeName));
    }

    #[Test]
    public function aRequestClassWithNoDocblockIsStillRead(): void
    {
        // The tag strategies look for @bodyParam tags on the FormRequest's own
        // docblock. With no docblock at all, handing on what reflection returns
        // for it would be a TypeError, which is not an Exception, so it would
        // escape the per-route catch and end the whole run instead of costing
        // one endpoint.
        $endpoint = $this->extract('formRequests.undocumented');

        $this->assertSame('The title.', $endpoint->bodyParameters['title']->description);
        $this->assertSame('Hello', $endpoint->bodyParameters['title']->example);
    }

    #[Test]
    public function aRequestThatNamesQueryParametersIsReadByTheQueryStrategyOnly(): void
    {
        $endpoint = $this->extract('formRequests.search');

        // Claimed by the query side...
        $this->assertSame('What to search for.', $endpoint->queryParameters['q']->description);
        $this->assertSame(25, $endpoint->queryParameters['per_page']->example);
        // ...and therefore not documented as a body parameter as well.
        $this->assertSame([], $endpoint->bodyParameters);
    }

    #[Test]
    public function rulesAreReadOutOfAValidatorMethodToo(): void
    {
        $endpoint = $this->extract('formRequests.redeem');

        // The rules only exist inside validator(), which Scribe has to call —
        // and which asks the request which route it is on while it runs.
        $this->assertTrue($endpoint->bodyParameters['code']->required);
        $this->assertSame('ABC-123', $endpoint->bodyParameters['code']->example);
    }

    #[Test]
    public function theInstantiationHookGetsToBuildTheRequest(): void
    {
        $seen = null;

        Scribe::instantiateFormRequestUsing(function (string $className) use (&$seen) {
            $seen = $className;

            return new $className;
        });

        $this->assertSame('What to search for.', $this->extract('formRequests.search')->queryParameters['q']->description);
        $this->assertSame(SearchPostsRequest::class, $seen);
        $this->assertNotNull(Globals::$__instantiateFormRequestUsing);
    }

    #[Test]
    public function aRequestWithNoParameterDataIsDocumentedFromItsRulesAlone(): void
    {
        $buffer = new BufferedOutput;
        ConsoleOutputUtils::bootstrapOutput($buffer);

        $endpoint = $this->extract('formRequests.report');

        $this->assertTrue($endpoint->bodyParameters['reason']->required);
        $this->assertSame('string', $endpoint->bodyParameters['reason']->type);
        $this->assertStringContainsString('No bodyParameters() method found in', $buffer->fetch());
    }

    #[Test]
    public function aParameterMissingFromTheParameterDataIsCalledOutByName(): void
    {
        $buffer = new BufferedOutput;
        ConsoleOutputUtils::bootstrapOutput($buffer);
        // The message is a debug one: it only matters to whoever is looking at
        // why an example is missing.
        Globals::$shouldBeVerbose = true;

        $endpoint = $this->extract('formRequests.partial');

        $this->assertSame('The one with an entry.', $endpoint->bodyParameters['described']->description);
        $this->assertArrayHasKey('undescribed', $endpoint->bodyParameters);
        $this->assertStringContainsString("No data found for parameter 'undescribed'", $buffer->fetch());
    }

    #[Test]
    public function aRequestWithNothingToValidateDocumentsNoParameters(): void
    {
        $this->assertSame([], $this->extract('formRequests.open')->bodyParameters);
    }

    #[Test]
    public function tagsOnTheRequestAreReadInsteadOfTheMethods(): void
    {
        // Both places carry a `@queryParam`; the request's is the one that
        // describes the request, so the method's is not consulted at all.
        $params = $this->extract('formRequests.annotated')->queryParameters;

        $this->assertSame(2, $params['page']->example);
        $this->assertArrayNotHasKey('limit', $params);
    }
}
