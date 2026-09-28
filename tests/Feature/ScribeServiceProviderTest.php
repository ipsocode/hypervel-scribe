<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature;

use Hypervel\Support\Facades\View;
use Ipsocode\Scribe\Commands\DiffConfig;
use Ipsocode\Scribe\Commands\GenerateDocumentation;
use Ipsocode\Scribe\Commands\MakeStrategy;
use Ipsocode\Scribe\Matching\RouteMatcher;
use Ipsocode\Scribe\Matching\RouteMatcherInterface;
use Ipsocode\Scribe\Scribe;
use Ipsocode\Scribe\ScribeServiceProvider;
use Ipsocode\Scribe\Tests\CreatesMockEndpoints;
use Ipsocode\Scribe\Tests\TestCase;
use Ipsocode\Scribe\Tools\BladeMarkdownEngine;
use Ipsocode\Scribe\Writing\ExternalHtmlWriter;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;

/**
 * What the package promises a consuming application at boot: merged config, a
 * bound route matcher, a registered command, published resources and loaded
 * translations. None of it is exercised by extraction tests, and all of it is
 * the first thing to break when the provider is edited.
 */
class ScribeServiceProviderTest extends TestCase
{
    use CreatesMockEndpoints;

    #[Test]
    public function thePackageConfigIsMergedIntoTheApplication(): void
    {
        $this->assertIsArray(config('scribe'));
        // A value that only exists in the package's own config/scribe.php.
        $this->assertSame('hypervel', config('scribe.type'));
        $this->assertSame(['api/*'], config('scribe.routes.0.match.prefixes'));
    }

    #[Test]
    public function theMergedConfigReadsTheApplicationsOwnValues(): void
    {
        // config/scribe.php calls config('app.name') and config('app.url') at
        // merge time, so a provider that merges too early silently produces
        // documentation titled after the framework default.
        $this->assertSame(config('app.name') . ' API Documentation', config('scribe.title'));
        $this->assertSame(config('app.url'), config('scribe.base_url'));
    }

    #[Test]
    public function theRouteMatcherInterfaceIsBound(): void
    {
        $this->assertInstanceOf(RouteMatcher::class, $this->app->get(RouteMatcherInterface::class));
    }

    #[Test]
    public function theBoundRouteMatcherHonoursTheConfigOverride(): void
    {
        // `scribe.routeMatcher` is the documented extension point for
        // applications with routing this package cannot introspect.
        $this->assertSame(RouteMatcher::class, config('scribe.routeMatcher', RouteMatcher::class));
    }

    #[Test]
    public function thePackagesTranslationsAreLoadedUnderTheScribeNamespace(): void
    {
        // The extractor renders every auth description through `scribe::` keys,
        // so losing the namespace turns them into raw translation keys in the
        // generated docs rather than into an error. The layout those keys
        // depend on is covered in full by TranslationsTest.
        $this->assertSame('This API is not authenticated.', trans('scribe::scribe.auth.none'));
    }

    #[Test]
    public function theConfigAndTranslationsArePublishableUnderTaggedGroups(): void
    {
        // Untagged publish groups force consumers to publish everything at
        // once, which is why the provider tags them.
        $groups = ScribeServiceProvider::publishableGroups();

        $this->assertContains('scribe-config', $groups);
        $this->assertContains('scribe-translations', $groups);
    }

    #[Test]
    public function theThemesViewsAreRegisteredUnderTheScribeNamespace(): void
    {
        // Everything the HTML theme renders is addressed as `scribe::`, from the
        // theme entrypoint down to the components the endpoint view pulls in.
        $this->assertTrue(View::exists('scribe::themes.default.index'));
        $this->assertTrue(View::exists('scribe::themes.default.endpoint'));
        $this->assertTrue(View::exists('scribe::themes.elements.index'));
        $this->assertTrue(View::exists('scribe::components.field-details'));
        $this->assertTrue(View::exists('scribe::components.badges.http-method'));
        $this->assertTrue(View::exists('scribe::markdown.intro'));

        // The `external_*` types render one of these instead of a theme.
        foreach (ExternalHtmlWriter::THEMES as $theme) {
            $this->assertTrue(View::exists("scribe::external.{$theme}"), "scribe::external.{$theme} is missing.");
        }
    }

    #[Test]
    public function markdownBladeViewsAreCompiledByTheBlademdEngine(): void
    {
        // The example-request partials are `*.md.blade.php`: Blade first, then
        // Markdown. Without the extension mapping they resolve to the plain
        // Blade engine and their fenced code blocks reach the page as literal
        // backticks.
        $this->assertSame('blademd', View::getExtensions()['md.blade.php'] ?? null);
        $this->assertInstanceOf(BladeMarkdownEngine::class, View::getEngineResolver()->resolve('blademd'));
    }

    #[Test]
    public function theBlademdEngineRendersBladeAndThenMarkdown(): void
    {
        $html = View::make('scribe::partials.example-requests.bash', [
            'endpoint' => $this->createMockEndpointData(['uri' => 'api/users']),
            'baseUrl' => 'http://api.test',
        ])->render();

        // The partial is written as a fenced bash block; Markdown is what turns
        // it into a highlightable <code> element.
        $this->assertStringContainsString('<code class="language-bash">', $html);
        $this->assertStringContainsString('curl --request GET', $html);
        $this->assertStringContainsString('http://api.test/api/users', $html);
    }

    #[Test]
    public function theThemesViewsArePublishableInSeparateGroups(): void
    {
        // Overriding one partial should not mean taking a copy of the whole
        // theme, so the views publish in pieces as well as wholesale.
        $groups = ScribeServiceProvider::publishableGroups();

        $this->assertContains('scribe-views', $groups);
        $this->assertContains('scribe-examples', $groups);
        $this->assertContains('scribe-themes', $groups);
        $this->assertContains('scribe-markdown', $groups);
        $this->assertContains('scribe-external', $groups);
    }

    #[Test]
    public function thePackageAdvertisesItsProviderAndAliasForDiscovery(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        $this->assertContains(ScribeServiceProvider::class, $composer['extra']['hypervel']['providers']);
        $this->assertSame(Scribe::class, $composer['extra']['hypervel']['aliases']['Scribe']);
    }

    #[Test]
    public function theGenerateCommandIsRegisteredUnderItsSignature(): void
    {
        $command = $this->app->get(GenerateDocumentation::class);

        $this->assertInstanceOf(GenerateDocumentation::class, $command);
        $this->assertSame('scribe:generate', $command->getName());
    }

    #[Test]
    public function theStrategyScaffolderAndConfigDifferAreRegisteredToo(): void
    {
        // A command bound in the container but missing from `commands()` never
        // reaches Artisan, so resolving the class is not enough on its own —
        // these assert the name the CLI actually answers to.
        $this->assertSame('scribe:strategy', $this->app->get(MakeStrategy::class)->getName());
        $this->assertSame('scribe:config-diff', $this->app->get(DiffConfig::class)->getName());
    }

    #[Test]
    public function theAboutCommandReportsTheVersionAndOutputType(): void
    {
        // `php artisan about` is where an application's maintainer looks first;
        // the callback registered at boot is only run when they do.
        $this->artisan('about')
            ->expectsOutputToContain(Scribe::VERSION)
            ->assertSuccessful();
    }

    #[Test]
    public function anApplicationServingRequestsRegistersTheViewsButPublishesNothing(): void
    {
        // Publishing is a console-only concern; a worker handling HTTP requests
        // pays for the view namespace and nothing else.
        $provider = new ScribeServiceProvider($this->app);

        $runningInConsole = new ReflectionProperty($this->app, 'isRunningInConsole');
        $runningInConsole->setValue($this->app, false);

        try {
            (new ReflectionMethod($provider, 'registerViews'))->invoke($provider);
        } finally {
            $runningInConsole->setValue($this->app, true);
        }

        $this->assertNotEmpty(View::getFinder()->getHints()['scribe'] ?? []);
    }
}
