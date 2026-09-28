<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Commands;

use Hypervel\Console\Command;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\URL;
use Hypervel\Support\Str;
use InvalidArgumentException;
use Ipsocode\Camel\Camel;
use Ipsocode\Scribe\GroupedEndpoints\GroupedEndpointsFactory;
use Ipsocode\Scribe\Matching\RouteMatcherInterface;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils as c;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use Ipsocode\Scribe\Tools\Globals;
use Ipsocode\Scribe\Tools\PathConfig;
use Ipsocode\Scribe\Tools\RunState;
use Ipsocode\Scribe\Writing\Writer;

class GenerateDocumentation extends Command
{
    protected ?string $signature = "scribe:generate
                            {--force : Discard any changes you've made to the YAML or Markdown files}
                            {--no-extraction : Skip extraction of route and API info and just transform the YAML and Markdown files into HTML}
                            {--config=scribe : Choose which config file to use}
                            {--scribe-dir= : Specify the directory where Scribe stores its intermediate output and cache. Defaults to `.<config_file>`}
    ";

    protected string $description = 'Generate API documentation from your Hypervel routes.';

    protected DocumentationConfig $docConfig;

    protected bool $shouldExtract;

    protected bool $forcing;

    protected PathConfig $paths;

    public function handle(RouteMatcherInterface $routeMatcher, GroupedEndpointsFactory $groupedEndpointsFactory): int
    {
        // Run in-process (Artisan::call from a job, a schedule or a request),
        // the command shares a worker whose statics outlive it: start from a
        // clean slate rather than from whatever the last run cached.
        RunState::flush();

        try {
            $this->bootstrap();

            if (! empty($this->docConfig->get('default_group'))) {
                $this->warn('It looks like you just upgraded to Scribe v4.');
                $this->warn('Please run the upgrade command first: `php artisan scribe:upgrade`.');

                return self::FAILURE;
            }

            // Extraction stage - extract endpoint info either from app or existing Camel files (previously extracted data)
            $groupedEndpointsInstance = $groupedEndpointsFactory->make($this, $routeMatcher, $this->paths);
            $extractedEndpoints = $groupedEndpointsInstance->get();
            $userDefinedEndpoints = Camel::loadUserDefinedEndpoints(Camel::camelDir($this->paths));
            $groupedEndpoints = $this->mergeUserDefinedEndpoints($extractedEndpoints, $userDefinedEndpoints);

            // Output stage
            $configFileOrder = $this->docConfig->get('groups.order', []);
            $groupedEndpoints = Camel::prepareGroupedEndpointsForOutput($groupedEndpoints, $configFileOrder);

            if (! count($userDefinedEndpoints)) {
                // Update the example custom file if there were no custom endpoints
                $this->writeExampleCustomEndpoint();
            }

            /** @var Writer $writer */
            $writer = app(Writer::class, ['config' => $this->docConfig, 'paths' => $this->paths]);
            $writer->writeDocs($groupedEndpoints);

            // Upstream retired the automatic config-upgrade check that used to
            // run here, since the config file is no longer changing as
            // frequently. Its `--no-upgrade-check` flag went with it rather
            // than staying in the signature as a no-op; `scribe:config-diff`
            // reports the same difference on demand.

            $errored = $groupedEndpointsInstance->hasEncounteredErrors();
            $this->sayGoodbye(errored: $errored);

            return $errored ? self::INVALID : self::SUCCESS;
        } finally {
            // The forced origin is coroutine-scoped, not worker-global, but this
            // command may run in-process (Artisan::call) inside a caller's own
            // coroutine rather than a fresh one — clear it so it cannot leak
            // into whatever else that coroutine does after this command returns.
            URL::useOrigin(null);

            // And leave none of this run's caches — ASTs, docblocks, example
            // models, the console binding — behind for the rest of the worker.
            RunState::flush();
        }
    }

    public function isForcing(): bool
    {
        return $this->forcing;
    }

    public function shouldExtract(): bool
    {
        return $this->shouldExtract;
    }

    public function getDocConfig(): DocumentationConfig
    {
        return $this->docConfig;
    }

    public function bootstrap(): void
    {
        // The --verbose option is included with all Artisan commands.
        Globals::$shouldBeVerbose = $this->option('verbose');

        c::bootstrapOutput($this->output);
        c::setCommand($this);

        $configName = $this->option('config');
        if (! config($configName)) {
            throw new InvalidArgumentException("The specified config (config/{$configName}.php) doesn't exist.");
        }

        $this->paths = new PathConfig($configName);
        if ($this->hasOption('scribe-dir') && ! empty($this->option('scribe-dir'))) {
            $this->paths = new PathConfig(
                $configName,
                scribeDir: $this->option('scribe-dir')
            );
        }

        $this->docConfig = new DocumentationConfig(config($this->paths->configName));

        // Checked here as well as in the Writer, which is the thing that acts on
        // it: extraction runs first and can take minutes, and a typo in `type`
        // is worth hearing about before that rather than after.
        $this->docConfig->assertTypeIsSupported();

        // Force root URL so it works in Postman collection
        $baseUrl = $this->docConfig->get('base_url') ?? config('app.url');

        // Laravel spells this forceRootUrl() before 11.43; Hypervel's
        // UrlGenerator only ever had useOrigin().
        URL::useOrigin($baseUrl);

        $this->forcing = $this->option('force');
        $this->shouldExtract = ! $this->option('no-extraction');

        if ($this->forcing && ! $this->shouldExtract) {
            throw new InvalidArgumentException("Can't use --force and --no-extraction together.");
        }

        $this->runBootstrapHook();
    }

    protected function runBootstrapHook()
    {
        if (is_callable(Globals::$__bootstrap)) {
            call_user_func_array(Globals::$__bootstrap, [$this]);
        }
    }

    protected function mergeUserDefinedEndpoints(array $groupedEndpoints, array $userDefinedEndpoints): array
    {
        foreach ($userDefinedEndpoints as $endpoint) {
            $indexOfGroupWhereThisEndpointShouldBeAdded = Arr::first(array_keys($groupedEndpoints), function ($key) use ($groupedEndpoints, $endpoint) {
                $group = $groupedEndpoints[$key];

                return $group['name'] === ($endpoint['metadata']['groupName'] ?? $this->docConfig->get('groups.default', ''));
            });

            if ($indexOfGroupWhereThisEndpointShouldBeAdded !== null) {
                $groupedEndpoints[$indexOfGroupWhereThisEndpointShouldBeAdded]['endpoints'][] = $endpoint;
            } else {
                $newGroup = [
                    'name' => $endpoint['metadata']['groupName'] ?? $this->docConfig->get('groups.default', ''),
                    'description' => $endpoint['metadata']['groupDescription'] ?? null,
                    'endpoints' => [$endpoint],
                ];

                $groupedEndpoints[$newGroup['name']] = $newGroup;
            }
        }

        return $groupedEndpoints;
    }

    protected function writeExampleCustomEndpoint(): void
    {
        // We add an example to guide users in case they need to add a custom endpoint.
        copy(__DIR__ . '/../../resources/example_custom_endpoint.yaml', Camel::camelDir($this->paths) . '/custom.0.yaml');
    }

    protected function sayGoodbye(bool $errored = false): void
    {
        $message = 'All done.';
        $url = null;

        if ($this->docConfig->outputRoutedThroughApp()) {
            if ($this->docConfig->get('hypervel.add_routes')) {
                $url = url($this->docConfig->get('hypervel.docs_url'));
            }
        } elseif (Str::endsWith(base_path('public'), 'public') && Str::startsWith($this->docConfig->get('static.output_path'), 'public/')) {
            $url = url(str_replace('public/', '', $this->docConfig->get('static.output_path')));
        }

        $this->newLine();
        if ($url) {
            $this->components->twoColumnDetail($message, $url);
        } else {
            $this->components->info($message);
        }

        if ($errored) {
            $this->components->warn('Generated docs, but encountered some errors while processing routes.');
            $this->components->warn('Check the output above for details.');
        }
    }
}
