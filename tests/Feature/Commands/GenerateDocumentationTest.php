<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Commands;

use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Route as RouteFacade;
use Hypervel\Support\Facades\Storage;
use Hypervel\Support\Facades\URL;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\InteractsWithPublishedFiles;
use InvalidArgumentException;
use Ipsocode\Camel\BaseDTO;
use Ipsocode\Scribe\Commands\GenerateDocumentation;
use Ipsocode\Scribe\Exceptions\CouldntGetRouteDetails;
use Ipsocode\Scribe\Extracting\RouteDocBlocker;
use Ipsocode\Scribe\Scribe;
use Ipsocode\Scribe\Tests\DatabaseTestCase;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use Ipsocode\Scribe\Tools\Globals;
use Ipsocode\Scribe\Tools\PathConfig;
use Ipsocode\Scribe\Writing\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Yaml\Yaml;
use Workbench\App\Http\Controllers\HiddenController;
use Workbench\App\Http\Controllers\UndocumentableController;
use Workbench\App\Strategies\ThrowsAnErrorStrategy;

use function Hypervel\Testbench\workbench_path;

/**
 * The whole pipeline end to end (match, extract, cache the YAML, write every
 * output), the one suite where the stages have to agree with each other.
 * Relative paths (`.scribe/`, `static.output_path`) resolve against the app
 * root (`base_path()`), inside the Testbench skeleton, which
 * `assertFileContains()` and friends address.
 */
class GenerateDocumentationTest extends DatabaseTestCase
{
    use InteractsWithPublishedFiles;

    private string $intermediatePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->intermediatePath = base_path('.scribe');
        $this->cleanUpGeneratedFiles();
    }

    protected function tearDown(): void
    {
        $this->cleanUpGeneratedFiles();

        parent::tearDown();
    }

    /**
     * Remove every artefact a run leaves behind.
     *
     * Testbench deletes the skeleton on shutdown, so cleaning it only isolates
     * one test from the next.
     */
    private function cleanUpGeneratedFiles(): void
    {
        File::deleteDirectory($this->intermediatePath);
        File::deleteDirectory(base_path('public'));
        File::deleteDirectory($this->app->viewPath('scribe'));
        File::deleteDirectory($this->app->publicPath('vendor/scribe'));
        Storage::disk('local')->deleteDirectory('scribe');
    }

    private function generate(array $options = []): int
    {
        // artisan() returns a fluent PendingCommand; run() is what actually
        // executes it and hands back the exit code.
        return $this->artisan('scribe:generate', $options)->run();
    }

    /**
     * The page the docs endpoint serves, as the browser receives it.
     */
    private function renderedDocs(): string
    {
        return (string) $this->get('/docs')->assertOk()->getContent();
    }

    #[Test]
    #[WithConfig('scribe.examples.faker_seed', 1234)]
    public function aSeededRunLeavesTheWorkerARandomGeneratorRatherThanTheSeededOne(): void
    {
        // Faker seeds PHP's process-wide Mersenne Twister, so after an
        // in-process run the worker's mt_rand(), shuffle() and the like would
        // otherwise carry on from a seed anyone can read in the config.
        $this->generate();

        $after = [mt_rand(), mt_rand()];

        mt_srand(1234);
        $seededStream = [];
        for ($i = 0; $i < 20000; ++$i) {
            $seededStream[] = mt_rand();
        }
        mt_srand();

        $continuesTheSeededStream = false;
        for ($i = 0; $i < count($seededStream) - 1; ++$i) {
            if ($seededStream[$i] === $after[0] && $seededStream[$i + 1] === $after[1]) {
                $continuesTheSeededStream = true;
                break;
            }
        }
        $this->assertFalse($continuesTheSeededStream, 'mt_rand() continued the sequence of seed 1234 after the run.');
    }

    private function openApiSpec(): array
    {
        return Yaml::parse(Storage::disk('local')->get('scribe/openapi.yaml'));
    }

    private function postmanCollection(): array
    {
        return json_decode(Storage::disk('local')->get('scribe/collection.json'), true);
    }

    #[Test]
    #[WithConfig('scribe.type', 'static')]
    public function relativePathsResolveAgainstTheAppRootNotTheWorkingDirectory(): void
    {
        $elsewhere = sys_get_temp_dir() . '/scribe-cwd-' . bin2hex(random_bytes(4));
        mkdir($elsewhere);
        $previous = getcwd();

        try {
            chdir($elsewhere);

            $this->assertSame(0, $this->generate());
            $this->assertSame(0, $this->generate(['--no-extraction' => true]));

            $this->assertDirectoryExists(base_path('.scribe/endpoints'));
            $this->assertFileExists(base_path('public/docs/index.html'));
            $this->assertSame([], array_diff(scandir($elsewhere), ['.', '..']));
        } finally {
            chdir($previous);
            File::deleteDirectory($elsewhere);
        }
    }

    #[Test]
    #[WithConfig('scribe.type', 'static')]
    public function aRelativeScribeDirResolvesAgainstTheAppRootNotTheWorkingDirectory(): void
    {
        $elsewhere = sys_get_temp_dir() . '/scribe-cwd-' . bin2hex(random_bytes(4));
        mkdir($elsewhere);
        $previous = getcwd();

        try {
            chdir($elsewhere);

            $this->assertSame(0, $this->generate(['--scribe-dir' => '.scribe-relative']));

            $this->assertDirectoryExists(base_path('.scribe-relative/endpoints'));
            $this->assertSame([], array_diff(scandir($elsewhere), ['.', '..']));
        } finally {
            chdir($previous);
            File::deleteDirectory($elsewhere);
            File::deleteDirectory(base_path('.scribe-relative'));
        }
    }

    #[Test]
    public function generatesAnOpenapiSpecAndAPostmanCollection(): void
    {
        $this->assertSame(0, $this->generate());

        $this->assertTrue(Storage::disk('local')->exists('scribe/openapi.yaml'));
        $this->assertTrue(Storage::disk('local')->exists('scribe/collection.json'));
    }

    #[Test]
    public function theGeneratedSpecDocumentsTheWorkbenchApi(): void
    {
        $this->generate();

        $paths = $this->openApiSpec()['paths'];

        $this->assertArrayHasKey('/api/users', $paths);
        $this->assertArrayHasKey('/api/posts', $paths);
        $this->assertArrayHasKey('/api/posts/{id}', $paths);
        // web.php's route is outside the configured `api/*` prefix.
        $this->assertArrayNotHasKey('/', $paths);

        $this->assertSame('List posts.', $paths['/api/posts']['get']['summary']);
        $this->assertSame(['Posts'], $paths['/api/posts']['get']['tags']);
    }

    #[Test]
    public function theGeneratedCollectionGroupsEndpointsByTheirGroupName(): void
    {
        $this->generate();

        $folders = collect($this->postmanCollection()['item'])->pluck('name')->all();

        $this->assertContains('Posts', $folders);
        $this->assertContains('Users', $folders);
    }

    #[Test]
    public function theIntermediateYamlIsWrittenForTheUserToEdit(): void
    {
        $this->generate();

        $this->assertDirectoryExists($this->intermediatePath . '/endpoints');
        $this->assertFileExists($this->intermediatePath . '/endpoints/00.yaml');
        // The example file exists so users know how to add a custom endpoint.
        $this->assertFileExists($this->intermediatePath . '/endpoints/custom.0.yaml');
    }

    #[Test]
    public function theIntroAndAuthMarkdownAreWrittenForTheUserToEdit(): void
    {
        $this->generate();

        // The prose layer: the two files a user is meant to rewrite, plus the
        // hashes Scribe compares against to know they did.
        $this->assertFileExists($this->intermediatePath . '/intro.md');
        $this->assertFileExists($this->intermediatePath . '/auth.md');
        $this->assertFileExists($this->intermediatePath . '/.filehashes');
    }

    #[Test]
    public function theGeneratedPageOpensWithTheIntroAndAuthSections(): void
    {
        $this->generate();

        // Both files are rendered into the page by the theme, so this is what
        // proves the two halves meet rather than each working alone.
        $this->assertFileContains([
            '<h1 id="introduction">Introduction</h1>',
            '<h1 id="authenticating-requests">Authenticating requests</h1>',
            // `auth.enabled` is false in the shipped config.
            'This API is not authenticated.',
            'data-unique="introduction"',
        ], 'resources/views/scribe/index.blade.php');
    }

    #[Test]
    public function theShippedIntroTextRendersAsProse(): void
    {
        $this->generate();

        $view = 'resources/views/scribe/index.blade.php';

        // The config's heredoc has to de-indent fully, or Markdown reads the
        // default intro as a code block and the <aside> arrives escaped.
        $this->assertFileContains(['<aside>As you scroll'], $view);
        $this->assertFileDoesNotContains(['<pre><code>This documentation aims'], $view);
    }

    #[Test]
    #[WithConfig('scribe.intro_text', 'Written in the application config.')]
    public function theConfiguredIntroTextReachesThePage(): void
    {
        $this->generate();

        $this->assertFileContains(['Written in the application config.'], 'resources/views/scribe/index.blade.php');
    }

    #[Test]
    public function editsToTheIntroMarkdownSurviveARegeneration(): void
    {
        $this->generate();

        $file = $this->intermediatePath . '/intro.md';
        file_put_contents($file, "# Introduction\n\nHand-written introduction.");

        $this->generate();

        $this->assertStringContainsString('Hand-written introduction.', (string) file_get_contents($file));
        $this->assertFileContains(['Hand-written introduction.'], 'resources/views/scribe/index.blade.php');
    }

    #[Test]
    public function forcingDiscardsEditsToTheIntroMarkdown(): void
    {
        $this->generate();

        $file = $this->intermediatePath . '/intro.md';
        file_put_contents($file, 'Hand-written introduction.');

        $this->generate(['--force' => true]);

        $this->assertStringNotContainsString('Hand-written introduction.', (string) file_get_contents($file));
    }

    #[Test]
    public function aSecondRunProducesTheSameSetOfDocumentedOperations(): void
    {
        $operations = function (): array {
            $found = [];
            foreach ($this->openApiSpec()['paths'] as $path => $pathItem) {
                foreach (array_diff(array_keys($pathItem), ['parameters']) as $method) {
                    $found[] = "{$method} {$path}";
                }
            }
            sort($found);

            return $found;
        };

        $this->generate();
        $first = $operations();

        $this->assertSame(0, $this->generate());

        $this->assertSame($first, $operations());

        // Deliberately not a whole-spec comparison. `examples.faker_seed`
        // reseeds the factories' Faker each run, but `fake()->unique()` (behind
        // UserFactory's emails) remembers what the first run handed out, so the
        // second run's example models differ in the response bodies even though
        // the documented surface is identical.
    }

    #[Test]
    public function aRunLeavesNoPerRunStateBehindButKeepsTheApplicationsHooks(): void
    {
        // Run in-process, the command shares a worker that outlives it. Its
        // caches must not: the next run would read this one's ASTs and
        // docblocks even after the controllers had changed.
        $hook = fn (array $paths) => null;
        Scribe::afterGenerating($hook);

        $this->assertSame(0, $this->generate());

        $this->assertSame([], (new ReflectionProperty(RouteDocBlocker::class, 'docBlocks'))->getValue());
        $this->assertSame([], (new ReflectionProperty(BaseDTO::class, 'propertyMetadata'))->getValue());
        $this->assertNull(ConsoleOutputUtils::getOutput());
        $this->assertSame($hook, Globals::$__afterGenerating);
    }

    #[Test]
    public function editsToTheIntermediateYamlSurviveARegeneration(): void
    {
        $this->generate();

        // This is the whole point of the intermediate directory: a human can
        // improve a description and not have it overwritten next run.
        $file = $this->intermediatePath . '/endpoints/00.yaml';
        $group = Yaml::parse((string) file_get_contents($file));
        $group['endpoints'][0]['metadata']['description'] = 'Hand-written description.';
        file_put_contents($file, Yaml::dump($group, 20, 2));

        $this->generate();

        $descriptions = collect($this->openApiSpec()['paths'])
            ->flatMap(fn (array $pathItem) => collect($pathItem)->pluck('description'))
            ->filter()
            ->all();

        $this->assertContains('Hand-written description.', $descriptions);
    }

    #[Test]
    public function aSectionDeletedFromTheIntermediateYamlDoesNotBreakTheMerge(): void
    {
        $this->generate();

        $file = $this->intermediatePath . '/endpoints/00.yaml';
        $group = Yaml::parse((string) file_get_contents($file));
        $uri = $group['endpoints'][0]['uri'];
        unset($group['endpoints'][0]['responseFields']);
        file_put_contents($file, Yaml::dump($group, 20, 2));

        $this->assertSame(0, $this->generate());
        $this->assertArrayHasKey('/' . $uri, $this->openApiSpec()['paths']);
    }

    #[Test]
    public function theEditsToAnEndpointSurviveARunInWhichItFails(): void
    {
        RouteFacade::get('api/undocumentable/type-error', [UndocumentableController::class, 'fine'])
            ->name('undocumentable.typeError');
        $this->assertSame(0, $this->generate());

        $edit = function (string $uri): void {
            foreach (glob($this->intermediatePath . '/endpoints/[0-9]*.yaml') as $file) {
                $group = Yaml::parse((string) file_get_contents($file));
                foreach ($group['endpoints'] as &$endpoint) {
                    if ($endpoint['uri'] === $uri) {
                        $endpoint['metadata']['description'] = 'Hand-written description.';
                    }
                }
                unset($endpoint);
                file_put_contents($file, Yaml::dump($group, 20, 2));
            }
        };
        $edit('api/undocumentable/type-error');

        $metadata = config('scribe.strategies.metadata');
        config(['scribe.strategies.metadata' => [...$metadata, ThrowsAnErrorStrategy::class]]);
        $this->assertSame(GenerateDocumentation::INVALID, $this->generate());

        $this->assertContains('api/undocumentable/type-error', $this->endpointUrisOnDisk('endpoints'));
        $this->assertContains('api/undocumentable/type-error', $this->endpointUrisOnDisk('endpoints.cache'));

        config(['scribe.strategies.metadata' => $metadata]);
        $this->assertSame(0, $this->generate());

        $this->assertSame(
            'Hand-written description.',
            $this->openApiSpec()['paths']['/api/undocumentable/type-error']['get']['description'],
        );
    }

    /**
     * @return string[] the URIs of the endpoints in one of the intermediate directories
     */
    private function endpointUrisOnDisk(string $directory): array
    {
        $uris = [];
        foreach (glob("{$this->intermediatePath}/{$directory}/[0-9]*.yaml") as $file) {
            foreach (Yaml::parse((string) file_get_contents($file))['endpoints'] as $endpoint) {
                $uris[] = $endpoint['uri'];
            }
        }

        return $uris;
    }

    #[Test]
    public function forcingDiscardsEditsToTheIntermediateYaml(): void
    {
        $this->generate();

        $file = $this->intermediatePath . '/endpoints/00.yaml';
        $group = Yaml::parse((string) file_get_contents($file));
        $group['endpoints'][0]['metadata']['description'] = 'Hand-written description.';
        file_put_contents($file, Yaml::dump($group, 20, 2));

        $this->generate(['--force' => true]);

        $this->assertStringNotContainsString(
            'Hand-written description.',
            (string) file_get_contents($file),
        );
    }

    #[Test]
    public function anEndpointDeletedFromTheIntermediateYamlIsStillReExtracted(): void
    {
        $this->generate();

        // The cache still holds the endpoint the user just removed from the
        // editable copy, so the comparison has nothing on the latest side to
        // merge from — and the freshly extracted endpoint stands as it is.
        $file = $this->intermediatePath . '/endpoints/00.yaml';
        $group = Yaml::parse((string) file_get_contents($file));
        $removed = array_shift($group['endpoints']);
        file_put_contents($file, Yaml::dump($group, 20, 2));

        $this->generate();

        $this->assertArrayHasKey('/' . $removed['uri'], $this->openApiSpec()['paths']);
    }

    #[Test]
    public function aUserDefinedEndpointInANewGroupGetsAGroupOfItsOwn(): void
    {
        $this->generate();

        // The group name names nothing the application has, so there is no
        // group to merge into and one has to be made.
        file_put_contents($this->intermediatePath . '/endpoints/custom.0.yaml', Yaml::dump([[
            'httpMethods' => ['GET'],
            'uri' => 'api/hand-written',
            'metadata' => [
                'title' => 'A hand-written endpoint',
                'groupName' => 'Hand-written',
                'groupDescription' => 'Endpoints with no route behind them.',
                'description' => '',
            ],
            'headers' => [],
            'urlParameters' => [],
            'queryParameters' => [],
            'bodyParameters' => [],
            'responses' => [['status' => 200, 'content' => '{}', 'description' => 'OK']],
            'responseFields' => [],
        ]], 20, 2));

        $this->generate();

        $this->assertContains('Hand-written', array_column($this->openApiSpec()['tags'], 'name'));
    }

    #[Test]
    public function noExtractionRebuildsTheOutputFromTheIntermediateYamlAlone(): void
    {
        $this->generate();
        Storage::disk('local')->delete('scribe/openapi.yaml');

        $this->assertSame(0, $this->generate(['--no-extraction' => true]));

        $this->assertTrue(Storage::disk('local')->exists('scribe/openapi.yaml'));
    }

    #[Test]
    public function noExtractionWithNothingExtractedYetIsRejected(): void
    {
        // The flag reads the intermediate YAML instead of the routes, and there
        // is none until a run has written it — better to say so than to write
        // an empty set of docs over the previous ones.
        $this->expectException(InvalidArgumentException::class);

        $this->generate(['--no-extraction' => true]);
    }

    #[Test]
    public function forceAndNoExtractionTogetherAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->generate(['--force' => true, '--no-extraction' => true]);
    }

    #[Test]
    public function anUnknownConfigNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->generate(['--config' => 'no_such_config']);
    }

    #[Test]
    public function theIntermediateDirectoryCanBeMoved(): void
    {
        $custom = base_path('.scribe-custom');
        File::deleteDirectory($custom);

        try {
            $this->generate(['--scribe-dir' => '.scribe-custom']);

            $this->assertDirectoryExists($custom . '/endpoints');
            $this->assertDirectoryDoesNotExist($this->intermediatePath);
        } finally {
            File::deleteDirectory($custom);
        }
    }

    #[Test]
    public function anAbsoluteIntermediateDirectoryIsReadBackAndPrunedLikeARelativeOne(): void
    {
        // Outside the working directory, where a path re-rooted under getcwd()
        // would find nothing.
        $custom = sys_get_temp_dir() . '/scribe-absolute-dir-' . getmypid();
        File::deleteDirectory($custom);

        try {
            $this->generate(['--scribe-dir' => $custom]);

            $file = $custom . '/endpoints/00.yaml';
            $group = Yaml::parse((string) file_get_contents($file));
            $group['endpoints'][0]['metadata']['description'] = 'Hand-written description.';
            file_put_contents($file, Yaml::dump($group, 20, 2));
            // A group file from an earlier run that has no group any more.
            File::copy($file, $custom . '/endpoints/98.yaml');

            $this->generate(['--scribe-dir' => $custom]);

            $this->assertStringContainsString('Hand-written description.', (string) file_get_contents($file));
            $this->assertFileDoesNotExist($custom . '/endpoints/98.yaml');
            $this->assertFileExists($custom . '/endpoints/custom.0.yaml');
        } finally {
            File::deleteDirectory($custom);
        }
    }

    #[Test]
    public function theBootstrapHookRunsBeforeExtraction(): void
    {
        $ran = false;
        Scribe::bootstrap(function () use (&$ran): void {
            $ran = true;
        });

        $this->generate();

        $this->assertTrue($ran);
    }

    #[Test]
    public function theAfterGeneratingHookReceivesTheWrittenPaths(): void
    {
        $paths = null;
        Scribe::afterGenerating(function (array $generated) use (&$paths): void {
            $paths = $generated;
        });

        $this->generate();

        $this->assertNotNull($paths);
        $this->assertStringEndsWith('openapi.yaml', $paths['openapi']);
        $this->assertStringEndsWith('collection.json', $paths['postman']);
        // The hook is how users post-process the docs, so the theme's outputs
        // have to reach it too rather than staying null forever.
        $this->assertStringEndsWith('index.blade.php', $paths['blade']);
        $this->assertNull($paths['html']);
        $this->assertDirectoryExists($paths['assets']['css']);
        $this->assertDirectoryExists($paths['assets']['js']);
        $this->assertDirectoryExists($paths['assets']['images']);
    }

    #[Test]
    public function theAfterExtractingHookCanRewriteAnEndpoint(): void
    {
        Scribe::afterExtracting(function ($endpoint): void {
            $endpoint->metadata->title = 'Rewritten by the hook';
        });

        $this->generate();

        $summaries = collect($this->openApiSpec()['paths'])
            ->flatMap(fn (array $pathItem) => collect($pathItem)->pluck('summary'))
            ->filter()
            ->unique()
            ->all();

        $this->assertSame(['Rewritten by the hook'], array_values($summaries));
    }

    #[Test]
    #[WithConfig('scribe.type', 'static')]
    public function aStaticOutputTypeWritesToThePublicDirectory(): void
    {
        $this->generate();

        $this->assertFileExists(base_path('public/docs/openapi.yaml'));
        $this->assertFileExists(base_path('public/docs/collection.json'));
    }

    #[Test]
    #[WithConfig('scribe.type', 'static')]
    public function aStaticOutputTypeWritesASelfContainedHtmlSite(): void
    {
        $this->generate();

        $docs = base_path('public/docs');
        $this->assertFileExists($docs . '/index.html');
        $this->assertFileExists($docs . '/css/theme-default.style.css');
        $this->assertFileExists($docs . '/images/navbar.png');
        $this->assertNotEmpty(glob($docs . '/js/theme-default-*.js'));

        $html = (string) file_get_contents($docs . '/index.html');
        $this->assertStringContainsString('List posts.', $html);
        $this->assertStringContainsString('api/posts', $html);
        // Static docs are opened as files as often as they are served, so the
        // asset links stay relative.
        $this->assertStringContainsString('href="../docs/css/theme-default.style.css"', $html);
    }

    #[Test]
    public function filesALostRunLeftInTheBuildDirectoryAreNotPublished(): void
    {
        $build = base_path('.scribe/build');
        File::ensureDirectoryExists($build . '/images');
        file_put_contents($build . '/stale.txt', 'stale');
        file_put_contents($build . '/images/stale.png', 'stale');

        $this->assertSame(0, $this->generate());

        $this->assertFileDoesNotExist($this->app->publicPath('vendor/scribe/stale.txt'));
        $this->assertFileDoesNotExist($this->app->publicPath('vendor/scribe/images/stale.png'));
        $this->assertFilenameExists('public/vendor/scribe/css/theme-default.style.css');
    }

    #[Test]
    public function theHypervelOutputTypeWritesABladeViewAndPublishesTheAssets(): void
    {
        // `hypervel` is the shipped default: the page becomes a Blade view the
        // application can route to, and the assets move under public/vendor.
        $this->generate();

        $this->assertFilenameExists('resources/views/scribe/index.blade.php');
        $this->assertFilenameExists('public/vendor/scribe/css/theme-default.style.css');
        $this->assertFilenameExists('public/vendor/scribe/images/navbar.png');
        $this->assertNotEmpty(File::glob($this->app->publicPath('vendor/scribe/js/theme-default-*.js')));

        // public/docs was staging, not output.
        $this->assertDirectoryDoesNotExist(base_path('public/docs'));
    }

    #[Test]
    public function theBladeViewLinksItsAssetsThroughTheAssetHelper(): void
    {
        $this->generate();

        $this->assertFileDoesNotContains(['../docs/css/'], 'resources/views/scribe/index.blade.php');

        $page = $this->renderedDocs();
        $this->assertStringContainsString('href="' . asset('/vendor/scribe/css/theme-default.style.css') . '"', $page);
        $this->assertStringContainsString('src="' . asset('/vendor/scribe/images/navbar.png') . '"', $page);
        $this->assertMatchesRegularExpression(
            '#src="' . preg_quote(asset('/vendor/scribe/js/theme-default-'), '#') . '.+?\.js"#',
            $page,
        );
    }

    #[Test]
    public function aWorkerThatHasRenderedTheDocsServesTheNewPageAfterARegeneration(): void
    {
        // The view engine stops checking a view's source once it has seen its
        // compiled copy up to date, so a regeneration has to compile the page.
        Scribe::afterResponseCall(fn ($request, $endpoint, $response) => $response->setContent('OLD-PAGE-BODY'));
        $this->generate();
        $this->assertStringContainsString('OLD-PAGE-BODY', $this->renderedDocs());
        $this->assertStringContainsString('OLD-PAGE-BODY', $this->renderedDocs());

        Scribe::afterResponseCall(fn ($request, $endpoint, $response) => $response->setContent('NEW-PAGE-BODY'));
        $this->generate(['--force' => true]);

        $page = $this->renderedDocs();
        $this->assertStringContainsString('NEW-PAGE-BODY', $page);
        $this->assertStringNotContainsString('OLD-PAGE-BODY', $page);
    }

    #[Test]
    public function theBladeViewPrintsBladeSyntaxFromTheApplicationsDataInsteadOfRunningIt(): void
    {
        // The page holds what the application returned (here a response call's
        // body). Compiled as Blade, an echo, a raw echo or a directive stored in
        // the application's data would run on every request to the docs.
        $payloads = [
            'echo' => ['{{ str_repeat(chr(81), 7) }}', 'QQQQQQQ'],
            'raw echo' => ['{!! str_repeat(chr(87), 7) !!}', 'WWWWWWW'],
            'directive' => ['@if(false) HIDDEN-BRANCH @endif', null],
        ];
        $body = implode(' ', array_column($payloads, 0));
        Scribe::afterResponseCall(fn ($request, $endpoint, $response) => $response->setContent($body));

        $this->generate();

        $page = $this->renderedDocs();
        foreach ($payloads as $name => [$payload, $outputIfRun]) {
            $this->assertStringContainsString($payload, $page, "The {$name} is printed as written.");
            if ($outputIfRun !== null) {
                $this->assertStringNotContainsString($outputIfRun, $page, "The {$name} did not run.");
            }
        }
    }

    #[Test]
    #[WithConfig('scribe.hypervel.assets_directory', 'docs-assets')]
    public function theAssetsDirectoryIsConfigurable(): void
    {
        try {
            $this->generate();

            $this->assertFilenameExists('public/docs-assets/css/theme-default.style.css');
            $this->assertStringContainsString(
                'href="' . asset('/docs-assets/css/theme-default.style.css') . '"',
                $this->renderedDocs(),
            );
        } finally {
            File::deleteDirectory($this->app->publicPath('docs-assets'));
        }
    }

    #[Test]
    #[WithConfig('scribe.hypervel.assets_directory', '/docs-assets/')]
    public function aLeadingSlashInTheAssetsDirectoryDoesNotMakeAssetUrlsProtocolRelative(): void
    {
        try {
            $this->generate();

            $this->assertFilenameExists('public/docs-assets/css/theme-default.style.css');
            $page = $this->renderedDocs();
            $this->assertStringContainsString(asset('/docs-assets/css/theme-default.style.css'), $page);
            $this->assertStringNotContainsString('//docs-assets', $page);
        } finally {
            File::deleteDirectory($this->app->publicPath('docs-assets'));
        }
    }

    #[Test]
    #[WithConfig('scribe.hypervel.assets_directory', 'assets')]
    public function aRunLeavesTheAppsOwnFilesInTheAssetsDirectoryAlone(): void
    {
        $unrelated = $this->app->publicPath('assets/app.css');
        File::ensureDirectoryExists(dirname($unrelated));
        file_put_contents($unrelated, 'body {}');
        // An earlier run's theme file, which the new run replaces.
        File::ensureDirectoryExists($this->app->publicPath('assets/css'));
        file_put_contents($this->app->publicPath('assets/css/theme-default.old.css'), '');

        try {
            $this->generate();

            $this->assertFileExists($unrelated);
            $this->assertFileDoesNotExist($this->app->publicPath('assets/css/theme-default.old.css'));
            $this->assertFilenameExists('public/assets/css/theme-default.style.css');
        } finally {
            File::deleteDirectory($this->app->publicPath('assets'));
        }
    }

    #[Test]
    #[WithConfig('scribe.static.output_path', 'public')]
    public function aHypervelRunLeavesTheStaticOutputPathAlone(): void
    {
        $unrelated = base_path('public/robots.txt');
        File::ensureDirectoryExists(dirname($unrelated));
        file_put_contents($unrelated, 'User-agent: *');

        $this->generate();

        $this->assertFileExists($unrelated);
        $this->assertDirectoryDoesNotExist(base_path('public/css'));
    }

    #[Test]
    #[WithConfig('scribe.hypervel.assets_directory', 'docs')]
    public function anAssetsDirectoryNamedLikeTheDefaultOutputPathStillGetsItsAssets(): void
    {
        try {
            $this->generate();

            $this->assertFilenameExists('public/docs/css/theme-default.style.css');
            $this->assertFilenameExists('public/docs/js/theme-default-' . Scribe::version() . '.js');
        } finally {
            File::deleteDirectory($this->app->publicPath('docs'));
        }
    }

    #[Test]
    public function anAssetsDirectoryThatOverlapsTheBuildDirectoryIsRefused(): void
    {
        $writer = new Writer(new DocumentationConfig(config('scribe')), new PathConfig);
        $method = new ReflectionMethod($writer, 'assertDirectoriesDoNotOverlap');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('overlaps the directory Scribe builds the page in');

        $method->invoke($writer, '/app/.scribe', '/app/.scribe/build');
    }

    #[Test]
    #[WithConfig('scribe.type', 'external_static')]
    #[WithConfig('scribe.theme', 'rapidoc')]
    public function anExternalStaticOutputTypePointsTheViewerAtTheSpecBesideIt(): void
    {
        $this->generate();

        $docs = base_path('public/docs');
        $this->assertFileExists($docs . '/openapi.yaml');

        $html = (string) file_get_contents($docs . '/index.html');
        $this->assertStringContainsString('<rapi-doc', $html);
        $this->assertStringContainsString('spec-url="../docs/openapi.yaml"', $html);
        // Nothing on the page comes from the extraction: the viewer reads the
        // spec in the browser, and brings its own assets.
        $this->assertStringNotContainsString('List posts.', $html);
        $this->assertDirectoryDoesNotExist($docs . '/css');
    }

    #[Test]
    #[WithConfig('scribe.type', 'external_hypervel')]
    #[WithConfig('scribe.theme', 'scalar')]
    public function anExternalHypervelOutputTypeWritesTheViewerAsABladeView(): void
    {
        $this->generate();

        $this->assertTrue(Storage::disk('local')->exists('scribe/openapi.yaml'));
        $this->assertFileContains(['Scalar.createApiReference'], 'resources/views/scribe/index.blade.php');
        $this->assertDirectoryDoesNotExist(base_path('public/docs'));
    }

    #[Test]
    #[WithConfig('scribe.type', 'blade')]
    public function anOutputTypeNobodyImplementsStopsTheRunBeforeExtraction(): void
    {
        // Treated like any other type the app does not route, an unknown type
        // would behave as "static": the run would report success and leave an
        // index.html in public/docs while the application waited for a Blade view.
        try {
            $this->generate();
            $this->fail('Expected the run to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("'blade', which is not an output type Scribe knows", $e->getMessage());
        }

        // Rejected in bootstrap(), so nothing was extracted and nothing written.
        $this->assertDirectoryDoesNotExist($this->intermediatePath);
        $this->assertDirectoryDoesNotExist(base_path('public/docs'));
        $this->assertFalse(Storage::disk('local')->exists('scribe/openapi.yaml'));
    }

    #[Test]
    #[WithConfig('scribe.type', 'external_hypervel')]
    #[WithConfig('scribe.theme', 'scalar')]
    #[WithConfig('scribe.hypervel.add_routes', false)]
    public function anExternalHypervelRunWarnsWhenNothingServesTheSpec(): void
    {
        // The spec goes to storage/, which the browser cannot reach, so the page
        // has to link it by route. Turning the docs endpoints off without
        // registering one leaves the viewer pointed at a path that does not
        // exist, and a viewer with no spec renders an empty page rather than an
        // error.
        $this->artisan('scribe:generate')
            ->expectsOutputToContain("no route named 'scribe.openapi' is registered")
            ->run();

        $this->assertFileContains(['..\/docs\/openapi.yaml'], 'resources/views/scribe/index.blade.php');
    }

    #[Test]
    #[DataProvider('externalThemes')]
    #[WithConfig('scribe.type', 'external_hypervel')]
    public function theViewerFetchesTheSpecThroughTheAppWhenARouteServesIt(string $theme, string $link): void
    {
        // The Blade view is rendered by the app, so the page names the route
        // serving the spec instead of linking a file under storage/ that the
        // browser has no way to reach.
        config(['scribe.theme' => $theme]);

        $this->generate();

        $this->assertFileDoesNotContains(['openapi.yaml'], 'resources/views/scribe/index.blade.php');
        $this->assertStringContainsString(
            str_replace('%url%', route('scribe.openapi'), $link),
            $this->renderedDocs(),
        );
    }

    /**
     * How each viewer's page should name the route serving the spec.
     *
     * Every viewer spells that URL differently — Scalar's is inside JSON, so
     * its slashes arrive escaped — and each spelling is a separate replacement.
     */
    public static function externalThemes(): array
    {
        return [
            'scalar' => ['scalar', '{"url":"%url%"}'],
            'elements' => ['elements', 'apiDescriptionUrl="%url%"'],
            'rapidoc' => ['rapidoc', 'spec-url="%url%"'],
        ];
    }

    #[Test]
    public function theBladeViewLinksTheCollectionAndSpecThroughTheirRoutes(): void
    {
        // Both files land under scribe/ on the local disk, which the browser
        // cannot reach, so the theme's relative links only resolve once the
        // docs endpoints stand in front of them.
        $this->generate();

        $page = $this->renderedDocs();
        $this->assertStringContainsString('href="' . route('scribe.postman') . '"', $page);
        $this->assertStringContainsString('href="' . route('scribe.openapi') . '"', $page);
    }

    #[Test]
    #[WithConfig('scribe.hypervel.add_routes', false)]
    public function theBladeViewKeepsItsRelativeLinksWhenNoRouteServesTheFiles(): void
    {
        // `route()` throws at render time for a name nobody registered, so an
        // application routing the docs itself gets the relative links left alone
        // to repoint, rather than a page that cannot render at all.
        $this->generate();

        $view = 'resources/views/scribe/index.blade.php';
        $this->assertFileContains(['href="../docs/collection.json"', 'href="../docs/openapi.yaml"'], $view);
        $this->assertFileDoesNotContains(['route("scribe.'], $view);
    }

    #[Test]
    #[WithConfig('scribe.openapi.enabled', false)]
    public function disablingAnOutputSkipsWritingIt(): void
    {
        $this->generate();

        $this->assertFalse(Storage::disk('local')->exists('scribe/openapi.yaml'));
        $this->assertTrue(Storage::disk('local')->exists('scribe/collection.json'));
    }

    #[Test]
    #[WithConfig('scribe.postman.overrides', ['info.version' => '9.9.9'])]
    public function postmanOverridesAreAppliedToTheWrittenCollection(): void
    {
        $this->generate();

        $this->assertSame('9.9.9', $this->postmanCollection()['info']['version']);
    }

    #[Test]
    public function aUserDefinedEndpointIsMergedIntoItsGroup(): void
    {
        $this->generate();

        // A custom endpoint is a YAML file the user drops into the camel dir;
        // it has no route behind it and must survive regeneration.
        file_put_contents($this->intermediatePath . '/endpoints/custom.0.yaml', Yaml::dump([[
            'httpMethods' => ['GET'],
            'uri' => 'api/custom',
            'metadata' => ['title' => 'A hand-written endpoint', 'groupName' => 'Posts', 'description' => ''],
            'headers' => [],
            'urlParameters' => [],
            'queryParameters' => [],
            'bodyParameters' => [],
            'responses' => [['status' => 200, 'content' => '{}', 'description' => 'OK']],
            'responseFields' => [],
        ]], 20, 2));

        $this->generate();

        $this->assertArrayHasKey('/api/custom', $this->openApiSpec()['paths']);
    }

    /**
     * Add the routes a run has to survive rather than document.
     *
     * Registered per test rather than in `defineRoutes()`, so the assertions
     * above keep describing the Workbench API as it stands.
     */
    private function registerUndocumentableRoutes(): void
    {
        RouteFacade::get('api/undocumentable/gone', [UndocumentableController::class, 'noSuchMethod'])
            ->name('undocumentable.gone');
        RouteFacade::get('api/undocumentable/hidden-class', [HiddenController::class, 'show'])
            ->name('undocumentable.hiddenClass');
        RouteFacade::get('api/undocumentable/hidden-method', [UndocumentableController::class, 'hiddenMethod'])
            ->name('undocumentable.hiddenMethod');
        RouteFacade::get('api/undocumentable/throws', [UndocumentableController::class, 'missingResponseFile'])
            ->name('undocumentable.throws');
        RouteFacade::get('api/undocumentable/closure', fn () => ['ok' => true])
            ->name('undocumentable.closure');
    }

    /**
     * @return string[] the URIs the last run documented
     */
    private function documentedUris(): array
    {
        return array_map(
            fn (string $path): string => mb_ltrim($path, '/'),
            array_keys($this->openApiSpec()['paths'] ?? []),
        );
    }

    #[Test]
    public function documentsTheRoutesItCanAndSkipsTheOnesItCannot(): void
    {
        // One undocumentable route in an application must not cost the user the
        // rest of their documentation.
        $this->registerUndocumentableRoutes();

        $this->generate();

        $documented = $this->documentedUris();

        // A missing controller method, a hidden controller, a hidden method and
        // one whose extraction throws are all skipped...
        $this->assertNotContains('api/undocumentable/gone', $documented);
        $this->assertNotContains('api/undocumentable/hidden-class', $documented);
        $this->assertNotContains('api/undocumentable/hidden-method', $documented);
        $this->assertNotContains('api/undocumentable/throws', $documented);

        // ...while the closure route is documented like any other.
        $this->assertContains('api/undocumentable/closure', $documented);
    }

    #[Test]
    public function aRouteToAMissingControllerClassIsSkippedWithAWarning(): void
    {
        RouteFacade::get('api/undocumentable/missing-class', ['Workbench\App\Http\Controllers\NoSuchController', 'index'])
            ->name('undocumentable.missingClass');

        $this->assertSame(GenerateDocumentation::SUCCESS, $this->generate());

        $documented = $this->documentedUris();
        $this->assertNotContains('api/undocumentable/missing-class', $documented);
        $this->assertContains('api/posts', $documented);
    }

    #[Test]
    public function aRouteWhoseExtractionThrowsAnErrorDoesNotStopTheRun(): void
    {
        // A TypeError is an Error, not an Exception: it must be caught per
        // route like any other failure instead of ending the run.
        RouteFacade::get('api/undocumentable/type-error', [UndocumentableController::class, 'fine'])
            ->name('undocumentable.typeError');
        config(['scribe.strategies.metadata' => [
            ...config('scribe.strategies.metadata'),
            ThrowsAnErrorStrategy::class,
        ]]);

        $this->assertSame(GenerateDocumentation::INVALID, $this->generate());

        $documented = $this->documentedUris();
        $this->assertNotContains('api/undocumentable/type-error', $documented);
        $this->assertContains('api/posts', $documented);
    }

    #[Test]
    public function aRunThatSkippedRoutesStillWritesItsOutput(): void
    {
        $this->registerUndocumentableRoutes();

        // The run still writes its output, but a route it couldn't document is
        // reported as an error via the exit code rather than silently swallowed.
        $this->assertSame(GenerateDocumentation::INVALID, $this->generate());

        $this->assertTrue(Storage::disk('local')->exists('scribe/openapi.yaml'));
    }

    #[Test]
    public function aRouteWhoseActionNamesNoMethodStopsTheRun(): void
    {
        // Not a route the router itself can produce — but an action assembled
        // by another package can leave the method off, and guessing at one
        // would document the wrong thing.
        RouteFacade::get('api/undocumentable/pair', [UndocumentableController::class, 'fine'])
            ->name('undocumentable.pair')
            ->setAction(['uses' => [UndocumentableController::class], 'as' => 'undocumentable.pair']);

        $this->expectException(CouldntGetRouteDetails::class);

        $this->generate();
    }

    #[Test]
    #[WithConfig('scribe.base_url', 'http://leaked-origin.test')]
    public function theForcedUrlOriginDoesNotLeakPastTheRun(): void
    {
        // The command forces a request-scoped origin for the Postman collection.
        // Testbench runs this whole test inside one coroutine, and the command
        // reuses it rather than spawning a fresh one, so a leaked origin would
        // still be visible here after generate() returns.
        $this->generate();

        $this->assertStringNotContainsString('leaked-origin.test', url('/'));
    }

    #[Test]
    #[WithConfig('scribe.base_url', 'http://leaked-origin.test')]
    public function theCallersForcedUrlOriginIsRestoredAfterTheRun(): void
    {
        URL::useOrigin('http://tenant-a.example');

        $this->generate();

        $this->assertStringStartsWith('http://tenant-a.example', url('/'));
    }

    #[Test]
    public function theWorkbenchRouteFilesAreWhereTheDocumentedApiComesFrom(): void
    {
        // Guards the fixture itself: if the Workbench route file stops being
        // discovered, every assertion above would pass against an empty API.
        $this->assertFileExists(workbench_path('routes', 'api.php'));
        $this->assertNotEmpty($this->workbenchRoute('posts.index'));
    }
}
