<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Extracting;

use Hypervel\Routing\Router;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\Extractor;
use Ipsocode\Scribe\Tests\DatabaseTestCase;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Http\Controllers\CrashingEndpointsController;

/**
 * Each endpoint here used to throw a TypeError that stopped the whole run; each
 * is now documented.
 */
class CrashingEndpointsTest extends DatabaseTestCase
{
    protected function defineRoutes(Router $router): void
    {
        foreach (['paginated', 'simplePaginated', 'cursorPaginated', 'noContent'] as $method) {
            $router->get("api/crashing/{$method}", [CrashingEndpointsController::class, $method])
                ->name("crashing.{$method}");
        }
        $router->post('api/crashing/aliased', [CrashingEndpointsController::class, 'storeAliased'])
            ->name('crashing.aliased');
    }

    private function extract(string $routeName): ExtractedEndpointData
    {
        return (new Extractor(new DocumentationConfig(config('scribe'))))
            ->processRoute($this->workbenchRoute($routeName));
    }

    #[Test]
    #[DataProvider('paginatedEndpoints')]
    public function aDocblockPageSizeIsReadAsAnInteger(string $routeName): void
    {
        $responses = $this->extract($routeName)->responses->toArray();

        $this->assertCount(1, $responses);
        $this->assertSame(200, $responses[0]['status']);
        $this->assertNotEmpty(json_decode($responses[0]['content'], true)['data']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function paginatedEndpoints(): array
    {
        return [
            'paginate' => ['crashing.paginated'],
            'simple' => ['crashing.simplePaginated'],
            'cursor' => ['crashing.cursorPaginated'],
        ];
    }

    #[Test]
    public function anEmptyResponseLeavesAnUntypedResponseFieldOnTheFallbackType(): void
    {
        $this->assertSame('', $this->extract('crashing.noContent')->responseFields['id']->type);
    }

    #[Test]
    public function aRouteToAnAliasedTraitMethodIsDocumented(): void
    {
        $endpoint = $this->extract('crashing.aliased');

        $this->assertSame('POST', $endpoint->httpMethods[0]);
        $this->assertArrayNotHasKey('title', $endpoint->bodyParameters);
    }
}
