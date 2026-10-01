<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Writing;

use InvalidArgumentException;
use Ipsocode\Scribe\Tests\CreatesMockEndpoints;
use Ipsocode\Scribe\Tests\TestCase;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use Ipsocode\Scribe\Tools\WritingUtils;
use Ipsocode\Scribe\Writing\HtmlWriter;
use PHPUnit\Framework\Attributes\Test;

/**
 * The theme: intermediate data in, a self-contained HTML site out. The only
 * place the Blade views render, so it also proves the `scribe::` namespace, the
 * `blademd` engine and the `x-scribe::` components resolve.
 */
class HtmlWriterTest extends TestCase
{
    use CreatesMockEndpoints;

    private string $source;

    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir() . '/scribe-html-' . bin2hex(random_bytes(6));
        $this->source = $base . '/source';
        $this->destination = $base . '/output';
        mkdir($this->source, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(dirname($this->source)));

        parent::tearDown();
    }

    /**
     * The writer under a consuming application's configuration, with overrides.
     *
     * The views read keys the package's own config always supplies —
     * `example_languages`, `try_it_out`, `auth` — so a bare array here would test
     * the theme against a configuration no application can actually have.
     */
    private function writer(array $config = []): HtmlWriter
    {
        return new HtmlWriter(new DocumentationConfig(array_replace_recursive(config('scribe'), $config)));
    }

    private function generate(array $config = [], ?array $groups = null): string
    {
        $groups ??= [$this->createGroup([$this->createMockEndpointData()])];

        $this->writer($config)->generate($groups, $this->source, $this->destination);

        return (string) file_get_contents($this->destination . '/index.html');
    }

    #[Test]
    public function writesAnIndexHtmlDocumentingEveryEndpoint(): void
    {
        $html = $this->generate(groups: [
            $this->createGroup([
                $this->createMockEndpointData(['uri' => 'api/users', 'metadata.title' => 'List users']),
                $this->createMockEndpointData(['uri' => 'api/users/{id}', 'metadata.title' => 'Show a user']),
            ]),
            $this->createGroup([$this->createMockEndpointData(['uri' => 'api/posts'])], 'Posts', 'Post endpoints.'),
        ]);

        $this->assertStringStartsWith('<!doctype html>', $html);
        $this->assertStringContainsString('List users', $html);
        $this->assertStringContainsString('Show a user', $html);
        $this->assertStringContainsString('api/posts', $html);
        // Group names become sidebar sections.
        $this->assertStringContainsString('data-unique="users"', $html);
        $this->assertStringContainsString('data-unique="posts"', $html);
        // Nothing may reach the output still holding Blade syntax.
        $this->assertStringNotContainsString('@foreach', $html);
        $this->assertStringNotContainsString('{{', $html);
    }

    #[Test]
    public function rendersAnExampleRequestPerConfiguredLanguage(): void
    {
        // The example-request partials are `*.md.blade.php`, which only render
        // if the `blademd` engine is registered for that extension.
        $html = $this->generate(['example_languages' => ['bash', 'javascript', 'php', 'python']]);

        foreach (['bash', 'javascript', 'php', 'python'] as $language) {
            $this->assertStringContainsString("{$language}-example", $html);
        }

        // Markdown fences from the partials arrive as highlightable code blocks.
        $this->assertStringContainsString('language-bash', $html);
        $this->assertStringContainsString('curl --request GET', $html);
    }

    #[Test]
    public function copiesTheThemesAssetsNextToTheDocument(): void
    {
        $this->generate();

        $this->assertFileExists($this->destination . '/css/theme-default.style.css');
        $this->assertFileExists($this->destination . '/css/theme-default.print.css');
        $this->assertFileExists($this->destination . '/images/navbar.png');
        // JS is versioned so a browser cannot serve a cached copy from an
        // older release against newer markup.
        $this->assertFileExists($this->destination . '/js/' . WritingUtils::getVersionedAsset('theme-default.js'));
        $this->assertFileExists($this->destination . '/js/' . WritingUtils::getVersionedAsset('tryitout.js'));
    }

    #[Test]
    public function leavesOutTheTryItOutScriptWhenTryItOutIsDisabled(): void
    {
        $this->generate(['try_it_out' => ['enabled' => false]]);

        $this->assertFileDoesNotExist($this->destination . '/js/' . WritingUtils::getVersionedAsset('tryitout.js'));
        $this->assertFileExists($this->destination . '/js/' . WritingUtils::getVersionedAsset('theme-default.js'));
    }

    #[Test]
    public function prunesAssetsLeftBehindByAnEarlierRelease(): void
    {
        mkdir($this->destination . '/js', 0o777, true);
        mkdir($this->destination . '/css', 0o777, true);
        file_put_contents($this->destination . '/js/theme-default-0.0.1.js', '// stale');
        file_put_contents($this->destination . '/css/theme-default.style.css', '/* stale */');

        $this->generate();

        $this->assertFileDoesNotExist($this->destination . '/js/theme-default-0.0.1.js');
        $this->assertStringNotContainsString('stale', (string) file_get_contents($this->destination . '/css/theme-default.style.css'));
    }

    #[Test]
    public function rendersTheIntroAndAuthMarkdownAndListsTheirHeadingsFirst(): void
    {
        file_put_contents($this->source . '/intro.md', "# Introduction\n\nWelcome aboard.\n\n## Getting started\n\nRead on.");
        file_put_contents($this->source . '/auth.md', "# Authenticating requests\n\nSend a token.");

        $html = $this->generate();

        $this->assertStringContainsString('<h1 id="introduction">Introduction</h1>', $html);
        $this->assertStringContainsString('<p>Welcome aboard.</p>', $html);
        $this->assertStringContainsString('<h1 id="authenticating-requests">Authenticating requests</h1>', $html);
        // Level 1 headings become sidebar sections, level 2 their subheadings.
        $this->assertStringContainsString('data-unique="introduction"', $html);
        $this->assertStringContainsString('data-unique="getting-started"', $html);
        // ...and they sort ahead of the endpoint groups.
        $this->assertLessThan(
            mb_strpos($html, 'data-unique="users"'),
            mb_strpos($html, 'data-unique="introduction"')
        );
    }

    #[Test]
    public function appendsTheOptionalAppendMarkdownAfterTheEndpoints(): void
    {
        file_put_contents($this->source . '/append.md', "# Further reading\n\n## Changelog\n\nSee the releases page.");

        $html = $this->generate();

        $this->assertStringContainsString('<h1 id="further-reading">Further reading</h1>', $html);
        $this->assertGreaterThan(
            mb_strpos($html, 'data-unique="users"'),
            mb_strpos($html, 'data-unique="further-reading"')
        );
        // The nesting rule is the same on this side of the endpoints as before
        // them: level 2 hangs off the level 1 above it.
        $this->assertGreaterThan(
            mb_strpos($html, 'data-unique="further-reading"'),
            mb_strpos($html, 'data-unique="changelog"')
        );
    }

    #[Test]
    public function generatesWithoutTheMarkdownFilesPresent(): void
    {
        // ApiDetails writes intro.md and auth.md during extraction, so a
        // `--no-extraction` run against an intermediate directory that predates
        // it finds neither. A missing file has to render as nothing rather than
        // taking the whole run down.
        $this->assertDirectoryExists($this->source);
        $this->assertFileDoesNotExist($this->source . '/intro.md');

        $html = $this->generate();

        $this->assertStringContainsString('List users', $html);
    }

    #[Test]
    public function rendersTheElementsThemeWhenItIsSelected(): void
    {
        $html = $this->generate(['theme' => 'elements']);

        $this->assertStringContainsString('List users', $html);
        $this->assertStringContainsString('css/theme-elements.style.css', $html);
        $this->assertFileExists($this->destination . '/css/theme-elements.style.css');
        // The elements theme ships no print stylesheet or theme script of its
        // own, and an asset that does not exist is simply not copied.
        $this->assertFileDoesNotExist($this->destination . '/css/theme-elements.print.css');
        $this->assertFileExists($this->destination . '/js/' . WritingUtils::getVersionedAsset('tryitout.js'));
    }

    #[Test]
    public function showsTheConfiguredLogoAndLeavesItOutWhenThereIsNone(): void
    {
        $this->assertStringContainsString(
            '<img src="img/logo.png" alt="logo"',
            $this->generate(['logo' => 'img/logo.png']),
        );
        $this->assertStringNotContainsString('alt="logo"', $this->generate(['logo' => false]));
    }

    #[Test]
    public function wiresUpTheTryItOutClientFromItsConfiguration(): void
    {
        $html = $this->generate([
            'try_it_out' => [
                'enabled' => true,
                'base_url' => 'http://sandbox.test',
                'use_csrf' => true,
                'csrf_url' => '/csrf',
            ],
        ]);

        $this->assertStringContainsString('var tryItOutBaseUrl = "http://sandbox.test";', $html);
        $this->assertStringContainsString('var csrfUrl = "/csrf";', $html);
        $this->assertStringContainsString('js/tryitout-', $html);
        $this->assertStringContainsString('Try it out', $html);
    }

    #[Test]
    public function leavesOutTheTryItOutControlsWhenTryItOutIsDisabled(): void
    {
        $html = $this->generate(['try_it_out' => ['enabled' => false]]);

        $this->assertStringNotContainsString('js/tryitout-', $html);
        $this->assertStringNotContainsString('Try it out', $html);
    }

    #[Test]
    public function theMetadataCarriesTheTitleAndTheDocumentLinks(): void
    {
        $metadata = $this->writer([
            'title' => 'Example API',
            'postman' => ['enabled' => true],
            'openapi' => ['enabled' => true],
        ])->getMetadata();

        $this->assertSame('Example API', $metadata['title']);
        $this->assertSame('../docs/collection.json', $metadata['postman_collection_url']);
        $this->assertSame('../docs/openapi.yaml', $metadata['openapi_spec_url']);
    }

    #[Test]
    public function theMetadataFallsBackToTheApplicationNameForTheTitle(): void
    {
        $metadata = $this->writer(['title' => null])->getMetadata();

        $this->assertSame(config('app.name') . ' Documentation', $metadata['title']);
    }

    #[Test]
    public function theMetadataOmitsTheLinksForOutputsThatAreTurnedOff(): void
    {
        $metadata = $this->writer([
            'postman' => ['enabled' => false],
            'openapi' => ['enabled' => false],
        ])->getMetadata();

        $this->assertNull($metadata['postman_collection_url']);
        $this->assertNull($metadata['openapi_spec_url']);
    }

    #[Test]
    public function theMetadataResolvesBearerAndBasicAuthOntoTheAuthorizationHeader(): void
    {
        $metadata = $this->writer(['auth' => ['in' => 'bearer', 'name' => 'token']])->getMetadata();

        $this->assertSame('Authorization', $metadata['auth']['name']);
        $this->assertSame('header', $metadata['auth']['location']);
        $this->assertSame('Bearer ', $metadata['auth']['prefix']);
    }

    #[Test]
    public function theMetadataLeavesOtherAuthLocationsAsTheyWereConfigured(): void
    {
        $metadata = $this->writer(['auth' => ['in' => 'query', 'name' => 'api_key']])->getMetadata();

        $this->assertSame('api_key', $metadata['auth']['name']);
        $this->assertSame('query', $metadata['auth']['location']);
        $this->assertSame('', $metadata['auth']['prefix']);
    }

    #[Test]
    public function theLastUpdatedStringResolvesItsDateToken(): void
    {
        $metadata = $this->writer(['last_updated' => 'Last updated: {date:Y}'])->getMetadata();

        $this->assertSame('Last updated: ' . date('Y'), $metadata['last_updated']);
    }

    #[Test]
    public function theLastUpdatedStringResolvesItsGitToken(): void
    {
        $metadata = $this->writer(['last_updated' => 'Built from {git:short}'])->getMetadata();

        $this->assertMatchesRegularExpression('/^Built from [0-9a-f]{7,}$/', $metadata['last_updated']);

        // `long` is the full hash, and starts with what `short` abbreviates.
        $long = $this->writer(['last_updated' => 'Built from {git:long}'])->getMetadata()['last_updated'];
        $this->assertMatchesRegularExpression('/^Built from [0-9a-f]{40}$/', $long);
    }

    #[Test]
    public function anUnknownGitFormatIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("only supports formats 'short' and 'long'");

        $this->writer(['last_updated' => 'Built from {git:describe}'])->getMetadata();
    }

    #[Test]
    public function theLastUpdatedStringIsUsedVerbatimWhenItHasNoTokens(): void
    {
        $metadata = $this->writer(['last_updated' => 'Ships when it ships'])->getMetadata();

        $this->assertSame('Ships when it ships', $metadata['last_updated']);
    }

    #[Test]
    public function assetsAreLinkedRelativeToTheOutputFolderForANonDefaultStaticPath(): void
    {
        // With the default `public/docs`, links go through `../docs/` so the
        // page works both as a file and when served by the application. Point
        // the output somewhere else and only a relative link can work.
        $html = $this->generate([
            'type' => 'static',
            'static' => ['output_path' => 'public/api-docs'],
        ]);

        $this->assertStringContainsString('href="./css/theme-default.style.css"', $html);
        $this->assertStringNotContainsString('../docs/css/', $html);
    }
}
