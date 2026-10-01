<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Extracting;

use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\Extractor;
use Ipsocode\Scribe\Extracting\Strategies\StaticData;
use Ipsocode\Scribe\Tests\DatabaseTestCase;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * How an application steers extraction: the `auth` block, per-strategy `only` /
 * `except` settings, the `static_data` strategy, and `inheritedDocsOverrides` on
 * a base controller.
 */
class ExtractorConfigurationTest extends DatabaseTestCase
{
    private function extract(string $routeName): ExtractedEndpointData
    {
        return (new Extractor(new DocumentationConfig(config('scribe'))))
            ->processRoute($this->workbenchRoute($routeName));
    }

    private function enableAuth(string $in, string $name = 'key', ?string $placeholder = '{YOUR_AUTH_KEY}'): void
    {
        config([
            'scribe.auth.enabled' => true,
            'scribe.auth.in' => $in,
            'scribe.auth.name' => $name,
            'scribe.auth.placeholder' => $placeholder,
            'scribe.auth.use_value' => null,
        ]);
    }

    #[Test]
    public function noAuthFieldIsAddedWhenAuthIsDisabled(): void
    {
        $endpoint = $this->extract('posts.destroy');

        $this->assertArrayNotHasKey('Authorization', $endpoint->headers);
    }

    #[Test]
    public function noAuthFieldIsAddedToAnUnauthenticatedEndpoint(): void
    {
        $this->enableAuth('bearer');

        // posts.index carries no @authenticated tag.
        $this->assertArrayNotHasKey('Authorization', $this->extract('posts.index')->headers);
    }

    #[Test]
    public function aBearerTokenIsDocumentedAsAnAuthorizationHeader(): void
    {
        $this->enableAuth('bearer');

        $endpoint = $this->extract('posts.destroy');

        $this->assertSame('Bearer {YOUR_AUTH_KEY}', $endpoint->headers['Authorization']);
        // The value Scribe would actually send during a response call is kept
        // separately from the placeholder shown in the docs.
        $this->assertSame('headers', $endpoint->auth[0]);
        $this->assertSame('Authorization', $endpoint->auth[1]);
        $this->assertStringStartsWith('Bearer ', $endpoint->auth[2]);
        $this->assertStringNotContainsString('{YOUR_AUTH_KEY}', $endpoint->auth[2]);
    }

    #[Test]
    public function basicAuthIsDocumentedAsAnAuthorizationHeader(): void
    {
        $this->enableAuth('basic');

        $this->assertSame('Basic {YOUR_AUTH_KEY}', $this->extract('posts.destroy')->headers['Authorization']);
    }

    #[Test]
    public function aCustomAuthHeaderIsDocumentedUnderItsOwnName(): void
    {
        $this->enableAuth('header', 'Api-Key');

        $this->assertSame('{YOUR_AUTH_KEY}', $this->extract('posts.destroy')->headers['Api-Key']);
    }

    #[Test]
    #[DataProvider('queryAuthLocations')]
    public function authSentInTheQueryIsDocumentedAsAQueryParameter(string $in): void
    {
        $this->enableAuth($in, 'api_key');

        $endpoint = $this->extract('posts.destroy');

        $this->assertSame('{YOUR_AUTH_KEY}', $endpoint->queryParameters['api_key']->example);
        $this->assertTrue($endpoint->queryParameters['api_key']->required);
        $this->assertSame('queryParameters', $endpoint->auth[0]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function queryAuthLocations(): array
    {
        return ['query' => ['query'], 'query_or_body' => ['query_or_body']];
    }

    #[Test]
    public function authSentInTheBodyIsDocumentedAsABodyParameter(): void
    {
        $this->enableAuth('body', 'api_key');

        $endpoint = $this->extract('posts.destroy');

        $this->assertSame('{YOUR_AUTH_KEY}', $endpoint->bodyParameters['api_key']->example);
        $this->assertSame('bodyParameters', $endpoint->auth[0]);
    }

    #[Test]
    public function aGeneratedTokenStandsInWhenNoPlaceholderIsConfigured(): void
    {
        $this->enableAuth('bearer', placeholder: null);

        $header = $this->extract('posts.destroy')->headers['Authorization'];

        $this->assertStringStartsWith('Bearer ', $header);
        $this->assertNotSame('Bearer ', $header);
    }

    #[Test]
    public function aConfiguredAuthValueIsUsedForResponseCalls(): void
    {
        $this->enableAuth('bearer');
        config(['scribe.auth.use_value' => 'a-real-token']);

        $this->assertSame('Bearer a-real-token', $this->extract('posts.destroy')->auth[2]);
    }

    #[Test]
    public function anUnknownAuthLocationAddsNothing(): void
    {
        $this->enableAuth('carrier_pigeon');

        $endpoint = $this->extract('posts.destroy');

        $this->assertArrayNotHasKey('Authorization', $endpoint->headers);
        $this->assertEmpty($endpoint->queryParameters);
    }

    #[Test]
    public function aStrategyRestrictedWithOnlyDoesNotRunElsewhere(): void
    {
        config(['scribe.strategies.headers' => [
            [StaticData::class, ['data' => ['X-Injected' => 'yes'], 'only' => ['GET api/posts']]],
        ]]);

        $this->assertSame('yes', $this->extract('posts.index')->headers['X-Injected']);
        $this->assertArrayNotHasKey('X-Injected', $this->extract('posts.show')->headers);
    }

    #[Test]
    public function aStrategyRestrictedWithExceptSkipsThoseRoutes(): void
    {
        config(['scribe.strategies.headers' => [
            [StaticData::class, ['data' => ['X-Injected' => 'yes'], 'except' => ['GET api/posts']]],
        ]]);

        $this->assertArrayNotHasKey('X-Injected', $this->extract('posts.index')->headers);
        $this->assertSame('yes', $this->extract('posts.show')->headers['X-Injected']);
    }

    #[Test]
    public function staticDataAcceptsTheShortSettingsForm(): void
    {
        // ['static_data', ['key' => 'value']] rather than
        // ['static_data', ['data' => [...]]].
        config(['scribe.strategies.headers' => [['static_data', ['X-Injected' => 'yes']]]]);

        $this->assertSame('yes', $this->extract('posts.index')->headers['X-Injected']);
    }

    #[Test]
    public function staticDataAcceptsTheExtendedSettingsForm(): void
    {
        config(['scribe.strategies.headers' => [
            ['static_data', ['data' => ['X-Injected' => 'yes'], 'only' => ['GET api/posts']]],
        ]]);

        $this->assertSame('yes', $this->extract('posts.index')->headers['X-Injected']);
    }
}
