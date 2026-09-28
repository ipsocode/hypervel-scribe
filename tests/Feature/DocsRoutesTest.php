<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature;

use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\Storage;
use Hypervel\Testbench\Attributes\WithConfig;
use Ipsocode\Scribe\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The docs endpoints the package registers for the output types that route the
 * docs through the application.
 *
 * The generated page names these routes rather than linking files — `Writer`
 * rewrites `../docs/collection.json` into `{{ route("scribe.postman") }}` — so
 * the names below are part of the package's contract with its own output, not
 * just with the reader.
 *
 * The files the endpoints serve are written here rather than generated: what is
 * under test is the routing, and `GenerateDocumentationTest` already covers the
 * writing end.
 */
class DocsRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanUpDocs();
    }

    protected function tearDown(): void
    {
        $this->cleanUpDocs();

        parent::tearDown();
    }

    private function cleanUpDocs(): void
    {
        File::deleteDirectory($this->app->viewPath('scribe'));
        Storage::disk('local')->deleteDirectory('scribe');
    }

    /**
     * Stand in for a `scribe:generate` run, with recognisable contents.
     */
    private function writeGeneratedDocs(): void
    {
        File::ensureDirectoryExists($this->app->viewPath('scribe'));
        File::put($this->app->viewPath('scribe/index.blade.php'), '<h1>{{ "Workbench" }} API</h1>');

        Storage::disk('local')->put('scribe/collection.json', '{"info":{"name":"Workbench API"}}');
        Storage::disk('local')->put('scribe/openapi.yaml', "openapi: 3.0.3\ninfo:\n  title: Workbench API\n");
    }

    #[Test]
    public function theDocsEndpointsAreRegisteredUnderTheNamesTheGeneratedPageUses(): void
    {
        // `Writer::performFinalTasksForHypervelType` looks these three up by
        // name before it rewrites the page's links, and silently leaves the
        // relative paths in place when they are missing.
        $this->assertTrue(Route::has('scribe'));
        $this->assertTrue(Route::has('scribe.postman'));
        $this->assertTrue(Route::has('scribe.openapi'));
    }

    #[Test]
    public function theDocsEndpointRendersTheGeneratedBladeView(): void
    {
        $this->writeGeneratedDocs();

        $this->get('/docs')
            ->assertOk()
            ->assertSee('Workbench API');
    }

    #[Test]
    public function thePostmanEndpointServesTheGeneratedCollectionAsJson(): void
    {
        $this->writeGeneratedDocs();

        // Served off disk like the OpenAPI endpoint below, so the body arrives
        // as a streamed file response rather than one `getContent()` can read.
        $response = $this->get('/docs.postman')
            ->assertOk()
            ->assertHeader('content-type', 'application/json');

        $this->assertJsonStringEqualsJsonString(
            '{"info":{"name":"Workbench API"}}',
            $response->streamedContent()
        );
    }

    #[Test]
    public function theOpenapiEndpointServesTheGeneratedSpecFile(): void
    {
        $this->writeGeneratedDocs();

        // The spec is served off disk rather than read into memory, so the body
        // arrives as a streamed file response.
        $response = $this->get('/docs.openapi')->assertOk();

        $this->assertStringContainsString('title: Workbench API', $response->streamedContent());
    }

    #[Test]
    #[WithConfig('scribe.hypervel.docs_url', '/api-docs')]
    public function theDocsUrlIsConfigurable(): void
    {
        $this->writeGeneratedDocs();

        $this->get('/api-docs')->assertOk();
        $this->get('/api-docs.postman')->assertOk();
        $this->get('/api-docs.openapi')->assertOk();

        $this->get('/docs')->assertNotFound();
    }

    #[Test]
    #[WithConfig('scribe.hypervel.middleware', ['web'])]
    public function theConfiguredMiddlewareIsAttachedToEveryDocsEndpoint(): void
    {
        // Docs behind an auth middleware is the whole reason the `hypervel`
        // type exists, so losing the middleware is a disclosure bug rather than
        // a cosmetic one.
        foreach (['scribe', 'scribe.postman', 'scribe.openapi'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "No route named [{$name}] is registered.");
            $this->assertContains('web', $route->middleware());
        }
    }

    #[Test]
    #[WithConfig('scribe.type', 'external_hypervel')]
    public function theExternalHypervelTypeGetsTheSameEndpoints(): void
    {
        // Its page is a shell that fetches the spec over HTTP, so of the two
        // routed types this is the one that cannot work without them at all.
        $this->assertTrue(Route::has('scribe'));
        $this->assertTrue(Route::has('scribe.postman'));
        $this->assertTrue(Route::has('scribe.openapi'));
    }

    #[Test]
    #[WithConfig('scribe.hypervel.add_routes', false)]
    public function nothingIsRegisteredWhenAddRoutesIsOff(): void
    {
        $this->assertFalse(Route::has('scribe'));
        $this->assertFalse(Route::has('scribe.postman'));
        $this->assertFalse(Route::has('scribe.openapi'));
    }

    #[Test]
    #[WithConfig('scribe.type', 'static')]
    public function nothingIsRegisteredForTheStaticTypes(): void
    {
        // Static docs are plain files under `public/`, which the web server
        // already serves; a route through the app would only shadow them.
        $this->assertFalse(Route::has('scribe'));
        $this->assertFalse(Route::has('scribe.postman'));
        $this->assertFalse(Route::has('scribe.openapi'));
    }
}
