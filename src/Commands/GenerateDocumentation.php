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
        // Run in-process (Artisan::call from a job, a schedule or a request), the
        // command shares a worker whose statics outlive it, so it starts clean.
        RunState::flush();

        try {
            $this->bootstrap();

            // Extraction stage: from the app, or from previously extracted Camel files.
            $groupedEndpointsInstance = $groupedEndpointsFactory->make($this, $routeMatcher, $this->paths);
            $extractedEndpoints = $groupedEndpointsInstance->get();
            $userDefinedEndpoints = Camel::loadUserDefinedEndpoints(Camel::camelDir($this->paths));
            $groupedEndpoints = $this->mergeUserDefinedEndpoints($extractedEndpoints, $userDefinedEndpoints);

            // Output stage
            $configFileOrder = $this->docConfig->get('groups.order', []);
            $groupedEndpoints = Camel::prepareGroupedEndpointsForOutput($groupedEndpoints, $configFileOrder);

            if (! count($userDefinedEndpoints)) {
                $this->writeExampleCustomEndpoint();
            }

            /** @var Writer $writer */
            $writer = app(Writer::class, ['config' => $this->docConfig, 'paths' => $this->paths]);
            $writer->writeDocs($groupedEndpoints);

            $errored = $groupedEndpointsInstance->hasEncounteredErrors();
            $this->sayGoodbye(errored: $errored);

            return $errored ? self::INVALID : self::SUCCESS;
        } finally {
            // The forced origin is coroutine-scoped, but an in-process run shares
            // the caller's coroutine, so clear it before returning.
            URL::useOrigin(null);

            // Leave none of this run's caches behind for the rest of the worker.
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
        // --verbose is built into every console command.
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

        // Also checked in the Writer, but extraction can take minutes, so a typo
        // in `type` is reported before it starts.
        $this->docConfig->assertTypeIsSupported();

        // Force the root URL so generated URLs, such as the Postman collection's, use it.
        $baseUrl = $this->docConfig->get('base_url') ?? config('app.url');
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
        // An example to follow when adding a custom endpoint.
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
