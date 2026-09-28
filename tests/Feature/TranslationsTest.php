<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature;

use Exception;
use Hypervel\Support\Facades\File;
use Ipsocode\Scribe\Tests\TestCase;
use Ipsocode\Scribe\Tools\Utils;
use PHPUnit\Framework\Attributes\Test;

/**
 * The package's translated strings back the auth descriptions the extractor
 * writes into the docs.
 *
 * The framework's FileLoader resolves `{path}/{locale}/{group}.php`, so the
 * group is the first segment of the key: `scribe::scribe.auth.details` reads
 * `lang/en/scribe.php`. The package shipped that file flat, as `lang/scribe.php`
 * with no locale directory, so no `scribe::` key resolved at all — this suite is
 * what keeps the layout and the call sites agreeing.
 */
class TranslationsTest extends TestCase
{
    #[Test]
    public function theScribeNamespacePointsAtThePackagesLangDirectory(): void
    {
        // The provider registers the hint through callAfterResolving('translator'),
        // so the translator has to be resolved before the hint exists.
        $hints = $this->app->get('translator')->getLoader()->namespaces();

        $this->assertArrayHasKey('scribe', $hints);
        $this->assertSame(realpath(dirname(__DIR__, 2) . '/lang'), realpath($hints['scribe']));
    }

    #[Test]
    public function aNamespacedKeyResolvesToItsString(): void
    {
        $this->assertSame('This API is not authenticated.', trans('scribe::scribe.auth.none'));
        $this->assertSame('Search', trans('scribe::scribe.labels.search'));
        $this->assertSame('Introduction', trans('scribe::scribe.headings.introduction'));
        $this->assertSame('Request', trans('scribe::scribe.endpoint.request'));
        $this->assertSame('Binary data', trans('scribe::scribe.endpoint.responses.binary'));
        $this->assertSame('View Postman collection', trans('scribe::scribe.links.postman'));
        $this->assertSame('Try it out ⚡', trans('scribe::scribe.try_it_out.open'));
    }

    #[Test]
    public function everyLangFileIsAGroupTheCallSitesCanReach(): void
    {
        // A file added at `lang/foo.php` rather than `lang/{locale}/scribe.php`
        // resolves to nothing, silently — which is exactly the bug this layout
        // fixes, and the one most likely to come back.
        $lang = dirname(__DIR__, 2) . '/lang';

        $strays = collect(File::files($lang))
            ->map(fn ($file) => $file->getFilename())
            ->all();

        $this->assertSame([], $strays, 'Translation files belong in lang/{locale}/, not directly in lang/.');

        // One group per locale, named `scribe`, so every key is `scribe::scribe.*`.
        foreach (File::directories($lang) as $locale) {
            $this->assertSame(
                ['scribe.php'],
                collect(File::files($locale))->map(fn ($file) => $file->getFilename())->values()->all(),
                basename($locale) . ' should hold exactly one group file, scribe.php.',
            );
        }
    }

    #[Test]
    public function theHelperSubstitutesPlaceholders(): void
    {
        $translated = Utils::trans('scribe::scribe.auth.instruction.header', [
            'parameterName' => 'Api-Key',
            'placeholder' => '{YOUR_KEY}',
        ]);

        $this->assertStringContainsString('**`Api-Key`**', $translated);
        $this->assertStringContainsString('{YOUR_KEY}', $translated);
    }

    #[Test]
    public function theHelperFallsBackToEnglishForAnUnsupportedLocale(): void
    {
        // The package ships English only. Under a different locale the
        // framework would render the raw key; the helper retries in `en` so the
        // generated docs read as prose rather than as `scribe::scribe.auth.details`.
        $this->app->get('translator')->setLocale('de');

        $this->assertSame(trans('scribe::scribe.auth.none', [], 'en'), Utils::trans('scribe::scribe.auth.none'));
    }

    #[Test]
    public function theHelperReportsAKeyItCannotTranslate(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('scribe::scribe.auth.no_such_key');

        Utils::trans('scribe::scribe.auth.no_such_key');
    }

    #[Test]
    public function anApplicationCanOverrideASingleString(): void
    {
        // Published to lang/vendor/scribe/{locale}/{group}.php, which the
        // FileLoader merges over the package's own — so an override of one key
        // must not drop the others.
        $override = $this->app->langPath('vendor/scribe/en');
        File::ensureDirectoryExists($override);
        File::put($override . '/scribe.php', "<?php\n\nreturn ['auth' => ['none' => 'Overridden.']];\n");

        try {
            $this->app->get('translator')->setLocale('en');

            $this->assertSame('Overridden.', trans('scribe::scribe.auth.none'));
            $this->assertSame(
                'All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.',
                trans('scribe::scribe.auth.details'),
            );
        } finally {
            File::deleteDirectory($this->app->langPath('vendor/scribe'));
        }
    }

    #[Test]
    public function theTranslationsArePublishedUnderTheirOwnTag(): void
    {
        $this->assertContains(
            'scribe-translations',
            \Ipsocode\Scribe\ScribeServiceProvider::publishableGroups(),
        );
    }
}
