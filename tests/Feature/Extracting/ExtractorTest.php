<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Extracting;

use Hypervel\Http\UploadedFile;
use Hypervel\Routing\Router;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\Extractor;
use Ipsocode\Scribe\Extracting\Strategies\StaticData;
use Ipsocode\Scribe\Scribe;
use Ipsocode\Scribe\Tests\DatabaseTestCase;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Http\Controllers\TagVariantsController;

/**
 * End-to-end extraction against the Workbench application.
 *
 * The individual strategies are small and could each be unit tested against a
 * stub route, but that is not where they break: they break on the interaction
 * between a real router, a real controller and a real form request. This suite
 * runs the whole strategy pipeline over routes an application actually
 * registered, which is the only place that interaction exists.
 */
class ExtractorTest extends DatabaseTestCase
{
    /**
     * Kept out of `workbench/routes/api.php` so the documented API the writer
     * and generate-command tests assert against stays as it is.
     */
    protected function defineRoutes(Router $router): void
    {
        $router->post('api/tags/upload', [TagVariantsController::class, 'fileUpload'])
            ->name('tags.upload');
    }

    private function extract(string $routeName): ExtractedEndpointData
    {
        $config = new DocumentationConfig(config('scribe'));

        return (new Extractor($config))->processRoute($this->workbenchRoute($routeName));
    }

    #[Test]
    public function readsMetadataFromAControllerDocblock(): void
    {
        $endpoint = $this->extract('posts.index');

        $this->assertSame('List posts.', $endpoint->metadata->title);
        $this->assertSame('Returns a page of posts, newest first.', $endpoint->metadata->description);
        // `@group Posts` sits on the class, so every method inherits it.
        $this->assertSame('Posts', $endpoint->metadata->groupName);
    }

    #[Test]
    public function readsMetadataFromPhpAttributes(): void
    {
        $endpoint = $this->extract('users.index');

        $this->assertSame('List users', $endpoint->metadata->title);
        $this->assertSame('Returns every user.', $endpoint->metadata->description);
        $this->assertSame('Users', $endpoint->metadata->groupName);
    }

    #[Test]
    public function readsQueryParametersFromADocblockTag(): void
    {
        $endpoint = $this->extract('posts.index');

        $this->assertArrayHasKey('page', $endpoint->queryParameters);
        $this->assertSame('integer', $endpoint->queryParameters['page']->type);
        $this->assertSame(2, $endpoint->queryParameters['page']->example);

        // `Enum:` in the description becomes the parameter's enum values.
        $this->assertSame(['draft', 'published'], $endpoint->queryParameters['status']->enumValues);
    }

    #[Test]
    public function readsQueryParametersFromAPhpAttribute(): void
    {
        $endpoint = $this->extract('users.index');

        $this->assertArrayHasKey('per_page', $endpoint->queryParameters);
        $this->assertFalse($endpoint->queryParameters['per_page']->required);
        $this->assertSame(15, $endpoint->queryParameters['per_page']->example);

        // An attribute that names no example gets a generated one, so the docs
        // still show a usable request.
        $this->assertNotNull($endpoint->queryParameters['sort']->example);
        // `No-example` is how an endpoint opts out of that.
        $this->assertNull($endpoint->queryParameters['cursor']->example);
    }

    #[Test]
    public function readsUrlParametersFromTheRouteAndItsAnnotations(): void
    {
        $endpoint = $this->extract('posts.show');

        $this->assertArrayHasKey('id', $endpoint->urlParameters);
        $this->assertTrue($endpoint->urlParameters['id']->required);
        $this->assertSame('The post id.', $endpoint->urlParameters['id']->description);
        $this->assertSame(3, $endpoint->urlParameters['id']->example);
    }

    #[Test]
    public function readsBodyParametersFromAFormRequest(): void
    {
        $endpoint = $this->extract('posts.store');

        $this->assertArrayHasKey('title', $endpoint->bodyParameters);
        $this->assertSame('string', $endpoint->bodyParameters['title']->type);
        $this->assertTrue($endpoint->bodyParameters['title']->required);
        // The description is the request's bodyParameters() hook first, with the
        // rule-derived sentence appended — both halves matter, so both are
        // asserted rather than just whichever one happens to come first.
        $this->assertStringStartsWith('The title of the post.', $endpoint->bodyParameters['title']->description);
        $this->assertStringContainsString('must not be greater than 120 characters', mb_strtolower($endpoint->bodyParameters['title']->description));

        $this->assertSame('boolean', $endpoint->bodyParameters['published']->type);
        $this->assertFalse($endpoint->bodyParameters['published']->required);
    }

    #[Test]
    public function jsonBodyParametersGetAContentTypeHeader(): void
    {
        $endpoint = $this->extract('posts.store');

        $this->assertSame('application/json', $endpoint->headers['Content-Type']);
    }

    #[Test]
    public function readsAResponseFromADocblockTag(): void
    {
        $endpoint = $this->extract('posts.store');

        $responses = collect($endpoint->responses->toArray())->keyBy('status');

        $this->assertArrayHasKey(201, $responses->all());
        $this->assertStringContainsString('My first post', $responses[201]['content']);
    }

    #[Test]
    public function marksAnEndpointAuthenticatedFromADocblockTag(): void
    {
        $this->assertTrue($this->extract('posts.destroy')->metadata->authenticated);
        $this->assertFalse($this->extract('posts.index')->metadata->authenticated);
    }

    #[Test]
    public function marksAnEndpointAuthenticatedFromAPhpAttribute(): void
    {
        $this->assertTrue($this->extract('users.me')->metadata->authenticated);
    }

    #[Test]
    public function recordsTheHttpMethodsAndUriOfTheRoute(): void
    {
        $endpoint = $this->extract('posts.destroy');

        $this->assertSame(['DELETE'], $endpoint->httpMethods);
        $this->assertSame('api/posts/{id}', $endpoint->uri);
    }

    #[Test]
    public function readsBodyParametersFromAnInlineRequestValidateCall(): void
    {
        $endpoint = $this->extract('comments.store');

        $this->assertArrayHasKey('body', $endpoint->bodyParameters);
        $this->assertSame('string', $endpoint->bodyParameters['body']->type);
        $this->assertTrue($endpoint->bodyParameters['body']->required);
        // The `@bodyParam` tag supplies the description the rules cannot.
        $this->assertStringStartsWith('The comment body.', $endpoint->bodyParameters['body']->description);
        $this->assertSame('Nice post.', $endpoint->bodyParameters['body']->example);

        $this->assertSame('boolean', $endpoint->bodyParameters['notify']->type);
    }

    #[Test]
    public function readsBodyParametersFromAnInlineValidatorFacadeCall(): void
    {
        $endpoint = $this->extract('comments.report');

        $this->assertArrayHasKey('reason', $endpoint->bodyParameters);
        $this->assertTrue($endpoint->bodyParameters['reason']->required);
        $this->assertSame(['spam', 'abuse'], $endpoint->bodyParameters['reason']->enumValues);
        // A comment above the rule is picked up as the parameter description.
        $this->assertStringContainsString('reason the comment is being reported', $endpoint->bodyParameters['reason']->description);
    }

    #[Test]
    public function readsRequestHeadersFromADocblockTag(): void
    {
        $this->assertSame('0c4c6bfa', $this->extract('comments.index')->headers['X-Trace-Id']);
    }

    #[Test]
    public function readsRequestHeadersFromAPhpAttribute(): void
    {
        $this->assertSame('0c4c6bfa', $this->extract('comments.pin')->headers['X-Trace-Id']);
    }

    #[Test]
    public function readsResponseFieldsFromDocblockTags(): void
    {
        $fields = $this->extract('comments.index')->responseFields;

        $this->assertSame('integer', $fields['id']->type);
        $this->assertSame('The comment id.', $fields['id']->description);
        $this->assertSame('string', $fields['body']->type);
    }

    #[Test]
    public function readsResponseFieldsFromAPhpAttribute(): void
    {
        $fields = $this->extract('comments.pin')->responseFields;

        $this->assertSame('boolean', $fields['pinned']->type);
        $this->assertSame('Whether the comment is now pinned.', $fields['pinned']->description);
    }

    #[Test]
    public function readsAResponseFromAFile(): void
    {
        $responses = collect($this->extract('comments.show')->responses->toArray())->keyBy('status');

        $this->assertStringContainsString('A canned response body.', $responses[200]['content']);

        // The second tag names a status and a scenario, and merges JSON over the
        // file's contents.
        $this->assertSame('comment not found', $responses[404]['description']);
        $this->assertNull(json_decode($responses[404]['content'], true)['data']);
    }

    #[Test]
    public function readsResponsesFromPhpAttributes(): void
    {
        $responses = collect($this->extract('comments.pin')->responses->toArray())->keyBy('status');

        $this->assertSame('{"pinned":true}', $responses[200]['content']);
        $this->assertSame('Pinned', $responses[200]['description']);
        $this->assertSame('Already pinned', $responses[409]['description']);
    }

    #[Test]
    public function readsAFileResponseFromAPhpAttribute(): void
    {
        // The attribute spelling of `@responseFile`, merge argument and all —
        // both resolve the path through storage_path() and merge the same way.
        $responses = collect($this->extract('comments.export')->responses->toArray())->keyBy('status');

        $body = json_decode($responses[200]['content'], true);

        $this->assertSame('The exported comment', $responses[200]['description']);
        // The merge is a top-level array_merge: a new key is added, and the
        // file's own keys survive alongside it.
        $this->assertTrue($body['meta']['exported']);
        $this->assertSame('Ada', $body['data']['author']);
    }

    #[Test]
    public function readsASubgroupFromAPhpAttribute(): void
    {
        $metadata = $this->extract('comments.pin')->metadata;

        $this->assertSame('Moderation', $metadata->subgroup);
        $this->assertSame('Endpoints for moderators.', $metadata->subgroupDescription);
    }

    #[Test]
    public function readsADeprecationFromAPhpAttribute(): void
    {
        $this->assertTrue($this->extract('comments.unpin')->metadata->deprecated);
    }

    #[Test]
    public function anUnauthenticatedAttributeOverridesTheGroupDefault(): void
    {
        $this->assertFalse($this->extract('comments.pin')->metadata->authenticated);
    }

    #[Test]
    public function rendersAnApiResourceResponseFromTheModelFactory(): void
    {
        $responses = collect($this->extract('users.index')->responses->toArray())->keyBy('status');

        $body = json_decode($responses[200]['content'], true);

        // `collection: true` wraps the resource, and the example model comes
        // from the Workbench UserFactory.
        $this->assertIsArray($body['data']);
        $this->assertArrayHasKey('email', $body['data'][0]);
    }

    #[Test]
    public function anApiResourceResponseHonoursTheRequestedFactoryState(): void
    {
        $responses = collect($this->extract('comments.unpin')->responses->toArray())->keyBy('status');

        $this->assertTrue(json_decode($responses[200]['content'], true)['data']['published']);
        $this->assertSame('The post', $responses[200]['description']);
    }

    #[Test]
    public function normalisesAModelBoundUrlParameterToAnId(): void
    {
        // Hypervel-style `{blog}` binding documents better as `{id}`: consumers
        // send an id, not a model.
        $endpoint = $this->extract('blogs.show');

        $this->assertSame('api/blogs/{id}', $endpoint->uri);
        $this->assertArrayHasKey('id', $endpoint->urlParameters);
    }

    #[Test]
    public function aNestedResourceKeepsTheParentParameterDistinguishable(): void
    {
        // Both parameters normalising to `{id}` would collide; the parent
        // becomes `{author_id}`.
        $this->assertSame(
            'api/authors/{author_id}/blogs/{id}',
            $this->extract('authors.blogs.show')->uri,
        );
    }

    #[Test]
    public function theUrlParameterNormalizerCanBeReplaced(): void
    {
        Scribe::normalizeEndpointUrlUsing(
            fn (string $url, $route, $method, $class, $default) => str_replace('{blog}', '{slug}', $url),
        );

        $this->assertSame('api/blogs/{slug}', $this->extract('blogs.show')->uri);
    }

    #[Test]
    public function theExtractorReportsTheRouteItIsProcessing(): void
    {
        // The documented hook applications use to vary example data per
        // endpoint; it has to be set during extraction and cleared after.
        $seen = null;
        Scribe::afterExtracting(function () use (&$seen): void {
            $seen = Extractor::getRouteBeingProcessed();
        });

        $this->extract('posts.index');

        $this->assertSame('api/posts', $seen?->uri());
        $this->assertNull(Extractor::getRouteBeingProcessed());
    }

    #[Test]
    public function anEndpointCanBeSkippedByTheConfiguredRules(): void
    {
        $extractor = new Extractor(new DocumentationConfig(config('scribe')));

        $health = $this->workbenchRoute('health');

        $this->assertTrue($extractor->shouldSkipRoute($health, ['health'], []));
        // An include list that the route misses skips it too.
        $this->assertTrue($extractor->shouldSkipRoute($health, [], ['users.*']));
        $this->assertFalse($extractor->shouldSkipRoute($health, [], ['health']));
        // Exclusion is checked first, so a route in both lists stays out.
        $this->assertTrue($extractor->shouldSkipRoute($health, ['health'], ['health']));
        $this->assertFalse($extractor->shouldSkipRoute($health, [], []));
    }

    #[Test]
    public function aFileParameterIsLoadedAndMakesTheEndpointMultipart(): void
    {
        $endpoint = $this->extract('tags.upload');

        // The file example is a path; a response call needs something it can
        // actually upload.
        $this->assertInstanceOf(UploadedFile::class, $endpoint->fileParameters['avatar']);
        // And files are split out of the body, so the two go to the request
        // separately.
        $this->assertArrayNotHasKey('avatar', $endpoint->cleanBodyParameters);
        $this->assertSame('Me, yesterday', $endpoint->cleanBodyParameters['caption']);

        $this->assertSame('multipart/form-data', $endpoint->headers['Content-Type']);
    }

    #[Test]
    public function anEndpointWithABodyGetsAJsonContentTypeIfNothingElseSetOne(): void
    {
        // The shipped config adds the header itself; an application that
        // removed that strategy still needs the request to say what it is
        // sending.
        config(['scribe.strategies.headers' => []]);

        $this->assertSame('application/json', $this->extract('posts.store')->headers['Content-Type']);
    }

    #[Test]
    public function aParameterIsNamedAfterItsKeyWhenTheStrategyDoesNotNameIt(): void
    {
        // Strategies may return `['id' => [...]]` without repeating the name
        // inside, and every parameter stage has to fill it in the same way.
        $data = ['data' => ['unnamed' => ['type' => 'string', 'description' => 'Named by its key.']]];
        config([
            'scribe.strategies.urlParameters' => [[StaticData::class, $data]],
            'scribe.strategies.queryParameters' => [[StaticData::class, $data]],
            'scribe.strategies.bodyParameters' => [[StaticData::class, $data]],
        ]);

        $endpoint = $this->extract('posts.show');

        $this->assertSame('unnamed', $endpoint->urlParameters['unnamed']->name);
        $this->assertSame('unnamed', $endpoint->queryParameters['unnamed']->name);
        $this->assertSame('unnamed', $endpoint->bodyParameters['unnamed']->name);
    }
}
