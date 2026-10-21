<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Extracting;

use Hypervel\Routing\Router;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\Extractor;
use Ipsocode\Scribe\Tests\DatabaseTestCase;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Http\Controllers\OneArgumentCallsController;

/**
 * A call that is named like a validation call but is not one used to raise
 * "Undefined array key" and cost the endpoint its documentation.
 */
class OneArgumentCallsTest extends DatabaseTestCase
{
    protected function defineRoutes(Router $router): void
    {
        foreach (['ownValidateHelper', 'ownValidatorMake', 'firstClassCallable', 'requestWithoutRules'] as $method) {
            $router->post("api/one-argument/{$method}", [OneArgumentCallsController::class, $method])
                ->name("oneArgument.{$method}");
        }
    }

    private function extract(string $method): ExtractedEndpointData
    {
        return (new Extractor(new DocumentationConfig(config('scribe'))))
            ->processRoute($this->workbenchRoute("oneArgument.{$method}"));
    }

    #[Test]
    public function aOneArgumentValidateHelperDoesNotHideTheRulesAfterIt(): void
    {
        $params = $this->extract('ownValidateHelper')->bodyParameters;

        $this->assertTrue($params['title']->required);
    }

    #[Test]
    public function aOneArgumentMakeOnAValidatorClassIsPassedOver(): void
    {
        $this->assertSame([], $this->extract('ownValidatorMake')->bodyParameters);
    }

    #[Test]
    public function aFirstClassCallableIsPassedOver(): void
    {
        $this->assertSame([], $this->extract('firstClassCallable')->bodyParameters);
    }

    #[Test]
    public function validateCallsOnTheRequestWithoutRulesArePassedOver(): void
    {
        $this->assertSame([], $this->extract('requestWithoutRules')->bodyParameters);
    }
}
