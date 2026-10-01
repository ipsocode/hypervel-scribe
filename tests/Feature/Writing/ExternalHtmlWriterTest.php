<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Writing;

use InvalidArgumentException;
use Ipsocode\Scribe\Tests\TestCase;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use Ipsocode\Scribe\Writing\ExternalHtmlWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The `external_*` output types: a shell page that hands the OpenAPI spec's URL
 * to a client-side viewer. The viewer draws the page in JavaScript this suite
 * cannot run, so the tests check what reaches it: the spec URL and the viewer's
 * own options.
 */
class ExternalHtmlWriterTest extends TestCase
{
    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->destination = sys_get_temp_dir() . '/scribe-external-' . bin2hex(random_bytes(6)) . '/output';
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(dirname($this->destination)));

        parent::tearDown();
    }

    /**
     * The writer under a consuming application's configuration, with overrides.
     *
     * The views read keys the package's own config always supplies — `theme`,
     * `try_it_out`, `external` — so a bare array here would test them against a
     * configuration no application can actually have.
     */
    private function writer(array $config = []): ExternalHtmlWriter
    {
        return new ExternalHtmlWriter(new DocumentationConfig(array_replace_recursive(config('scribe'), $config)));
    }

    private function generate(array $config = []): string
    {
        $config += ['type' => 'external_static'];

        $this->writer($config)->generate([], '.scribe', $this->destination);

        return (string) file_get_contents($this->destination . '/index.html');
    }

    #[Test]
    #[DataProvider('themes')]
    public function writesAnIndexHtmlThatMountsTheChosenViewerOnTheSpec(string $theme, string $marker, string $specUrl): void
    {
        $html = $this->generate(['theme' => $theme, 'title' => 'Example API']);

        $this->assertStringStartsWith('<!doctype html>', $html);
        $this->assertStringContainsString($marker, $html);
        $this->assertStringContainsString('<title>Example API</title>', $html);
        // The spec is the viewer's only input, so its URL has to reach it.
        $this->assertStringContainsString($specUrl, $html);
        // Nothing may reach the output still holding Blade syntax.
        $this->assertStringNotContainsString('@foreach', $html);
        $this->assertStringNotContainsString('{{', $html);
    }

    #[Test]
    #[DataProvider('themes')]
    public function copiesNoAssetsBecauseTheViewersLoadTheirOwn(string $theme, string $marker, string $specUrl): void
    {
        $this->generate(['theme' => $theme]);

        $this->assertSame(
            ['index.html'],
            array_values(array_diff(scandir($this->destination), ['.', '..'])),
            "The {$theme} viewer ({$marker}, reading {$specUrl}) loads its own assets from a CDN, so none are written.",
        );
    }

    /**
     * Each viewer, the call or element that mounts it, and how its view spells
     * the spec's URL.
     *
     * Those spellings are also what `Writer` rewrites into a `route()` call for
     * the hypervel types, so they are a contract between the two rather than a
     * detail of the markup.
     */
    public static function themes(): array
    {
        return [
            'scalar' => ['scalar', 'Scalar.createApiReference', '{"url":"..\/docs\/openapi.yaml"}'],
            'elements' => ['elements', '<elements-api', 'apiDescriptionUrl="../docs/openapi.yaml"'],
            'rapidoc' => ['rapidoc', '<rapi-doc', 'spec-url="../docs/openapi.yaml"'],
        ];
    }

    #[Test]
    public function scalarIsHandedItsConfiguredOptionsAlongsideTheSpecUrl(): void
    {
        $html = $this->generate([
            'theme' => 'scalar',
            'external' => ['scalar_config' => ['theme' => 'purple', 'hideDownloadButton' => true]],
        ]);

        $this->assertMatchesRegularExpression(
            '/Scalar\.createApiReference\(\'#app\', (\{.+})\)/',
            $html,
            'Scalar takes its whole configuration as one JSON object.',
        );
        preg_match('/Scalar\.createApiReference\(\'#app\', (\{.+})\)/', $html, $matches);

        $this->assertSame([
            'theme' => 'purple',
            'hideDownloadButton' => true,
            'url' => '../docs/openapi.yaml',
        ], json_decode($matches[1], true));
    }

    #[Test]
    public function elementsAndRapidocTakeTheirOptionsAsHtmlAttributes(): void
    {
        $this->assertStringContainsString(
            'logo="img/logo.png"',
            $this->generate(['theme' => 'elements', 'external' => ['html_attributes' => ['logo' => 'img/logo.png']]]),
        );
        $this->assertStringContainsString(
            'theme="dark"',
            $this->generate(['theme' => 'rapidoc', 'external' => ['html_attributes' => ['theme' => 'dark']]]),
        );
    }

    #[Test]
    public function theConfiguredAttributesWinOverTheOnesTheViewSets(): void
    {
        // Both are rendered, but an HTML element keeps the first value it is
        // given for an attribute — which is why the loop comes first in the view.
        $html = $this->generate([
            'theme' => 'rapidoc',
            'external' => ['html_attributes' => ['render-style' => 'view']],
        ]);

        $this->assertLessThan(
            mb_strpos($html, 'render-style="read"'),
            mb_strpos($html, 'render-style="view"'),
        );
    }

    #[Test]
    public function tryItOutIsTurnedOffInTheViewerWhenItIsDisabled(): void
    {
        $this->assertStringContainsString(
            'hideTryIt="true"',
            $this->generate(['theme' => 'elements', 'try_it_out' => ['enabled' => false]]),
        );
        $this->assertStringContainsString(
            'allow-try="false"',
            $this->generate(['theme' => 'rapidoc', 'try_it_out' => ['enabled' => false]]),
        );

        $this->assertStringContainsString(
            'hideTryIt=""',
            $this->generate(['theme' => 'elements', 'try_it_out' => ['enabled' => true]]),
        );
        $this->assertStringContainsString(
            'allow-try="true"',
            $this->generate(['theme' => 'rapidoc', 'try_it_out' => ['enabled' => true]]),
        );
    }

    #[Test]
    public function showsTheConfiguredLogoAndLeavesItOutWhenThereIsNone(): void
    {
        $this->assertStringContainsString(
            'logo="img/logo.png"',
            $this->generate(['theme' => 'elements', 'logo' => 'img/logo.png']),
        );
        $this->assertStringContainsString(
            '<img slot="logo" src="img/logo.png"/>',
            $this->generate(['theme' => 'rapidoc', 'logo' => 'img/logo.png']),
        );

        $this->assertStringNotContainsString('logo="', $this->generate(['theme' => 'elements', 'logo' => false]));
        $this->assertStringNotContainsString('slot="logo"', $this->generate(['theme' => 'rapidoc', 'logo' => false]));
    }

    #[Test]
    public function aThemeThatIsNotAnExternalViewerIsRejected(): void
    {
        // 'default' is the shipped theme, and it renders the endpoints itself —
        // there is no external view by that name. The error names the mistake
        // rather than a bare "view not found".
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("`scribe.theme` is set to 'default'");

        $this->generate(['theme' => 'default']);
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
    public function theSpecStaysLinkedEvenWhenTheOpenapiOutputIsTurnedOff(): void
    {
        // `Writer` writes the spec for the external types whichever way that
        // flag is set — it is the only thing the page has to show. Dropping the
        // link would leave the viewer pointed at nothing.
        $metadata = $this->writer([
            'postman' => ['enabled' => false],
            'openapi' => ['enabled' => false],
        ])->getMetadata();

        $this->assertNull($metadata['postman_collection_url']);
        $this->assertSame('../docs/openapi.yaml', $metadata['openapi_spec_url']);
    }

    #[Test]
    public function theSpecIsLinkedRelativeToTheOutputFolderForANonDefaultStaticPath(): void
    {
        // With the default `public/docs`, links go through `../docs/` so the
        // page works both as a file and when served by the application. Point
        // the output somewhere else and only a relative link can work.
        $html = $this->generate([
            'theme' => 'rapidoc',
            'static' => ['output_path' => 'public/api-docs'],
        ]);

        $this->assertStringContainsString('spec-url="./openapi.yaml"', $html);
    }
}
