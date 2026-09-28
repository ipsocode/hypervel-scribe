<?php

declare(strict_types=1);

namespace Ipsocode\Scribe;

use Hypervel\Contracts\View\Factory as ViewFactoryContract;
use Hypervel\Foundation\Console\AboutCommand;
use Hypervel\Support\ServiceProvider;
use Hypervel\Support\Str;
use Hypervel\View\Compilers\BladeCompiler;
use Ipsocode\Scribe\Commands\DiffConfig;
use Ipsocode\Scribe\Commands\GenerateDocumentation;
use Ipsocode\Scribe\Commands\MakeStrategy;
use Ipsocode\Scribe\Matching\RouteMatcher;
use Ipsocode\Scribe\Matching\RouteMatcherInterface;
use Ipsocode\Scribe\Tools\BladeMarkdownEngine;

class ScribeServiceProvider extends ServiceProvider
{
    /**
     * Register the package services.
     *
     * Only bind things into the container here — other providers may not
     * have booted yet.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/scribe.php', 'scribe');

        // Bind the route matcher implementation (overridable via config).
        // Resolved from inside the closure, not at register time, so a
        // config change (e.g. in tests) before the first resolution is honored.
        $this->app->bind(
            RouteMatcherInterface::class,
            fn () => $this->app->make(config('scribe.routeMatcher', RouteMatcher::class))
        );
    }

    /**
     * The nested config arrays whose entries merge by name.
     *
     * `mergeConfigFrom()` is a shallow `array_merge` at the top level, so
     * without this an application that hand-writes a partial `config/scribe.php`
     * loses every sibling default inside any array it touches. That is not
     * inert: an `auth` block naming only `enabled` would leave `auth.in` null,
     * which the HTML writer and the extractor's auth descriptions both branch
     * on — producing silently wrong documentation rather than an error.
     *
     * Listed here are the option arrays, whose keys are names. Two keys are
     * deliberately absent, for different reasons:
     *
     * - `routes` is a positional list. Merging it by key would graft an
     *   application's first entry over the package's first entry and leave the
     *   package's remaining entries behind, which is nobody's intent.
     * - `strategies` is a map of stage name to list, so merging it by key
     *   *would* work — each stage the application names replacing the package's,
     *   the rest surviving. It is out because the whole block is treated as one
     *   unit, which means an application that names one stage inherits no
     *   defaults for the other six and silently extracts nothing for them.
     *   ScribeConsumerConfigTest pins that; the README spells out the
     *   consequence for anyone writing the config file.
     */
    protected function mergeableOptions(string $name): array
    {
        return [
            'auth',
            'examples',
            'external',
            'fractal',
            'groups',
            'hypervel',
            'openapi',
            'postman',
            'static',
            'try_it_out',
        ];
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(): void
    {
        $this->registerViews();

        $this->bootRoutes();

        // Translations back the human-readable descriptions used by the
        // extractor (auth + validation rule descriptions).
        $this->loadTranslationsFrom(realpath(__DIR__ . '/../lang') ?: __DIR__ . '/../lang', 'scribe');

        // @codeCoverageIgnoreStart
        // A Hypervel application's alias loader already resolves `Str`, so this
        // only fires for one that dropped the alias from config/app.php.
        if (! class_exists('Str')) {
            // We don't want to have to use the FQN in our Blade files.
            class_alias(Str::class, 'Str');
        }
        // @codeCoverageIgnoreEnd

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/scribe.php' => config_path('scribe.php'),
            ], 'scribe-config');

            $this->publishes([
                __DIR__ . '/../lang/' => $this->app->langPath('vendor/scribe'),
            ], 'scribe-translations');

            $this->commands([
                GenerateDocumentation::class,
                MakeStrategy::class,
                DiffConfig::class,
            ]);

            AboutCommand::add('Scribe', fn () => [
                'Version' => Scribe::VERSION,
                'Output Type' => (string) config('scribe.type', 'hypervel'),
            ]);
        }
    }

    /**
     * Serve the generated docs from the application itself.
     *
     * Only for the output types that put the docs behind a route in the first
     * place — `hypervel` writes a Blade view, `external_hypervel` writes the
     * viewer's shell as one, and both keep the spec and the collection under
     * `storage/`, out of the browser's reach. The `static` types write plain
     * files into `public/`, which the web server already serves.
     *
     * An application that would rather route the docs itself sets
     * `hypervel.add_routes` to false and nothing here is registered.
     */
    protected function bootRoutes(): void
    {
        $type = (string) config('scribe.type', 'hypervel');

        if (Str::endsWith($type, 'hypervel') && config('scribe.hypervel.add_routes', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/hypervel.php');
        }
    }

    /**
     * Register the documentation theme's views and the engine they need.
     *
     * The example-request partials are `*.md.blade.php`: Blade first, then
     * Markdown. That is what the `blademd` engine is for, and the extension has
     * to be mapped to it before any view is resolved.
     */
    protected function registerViews(): void
    {
        $this->callAfterResolving(ViewFactoryContract::class, function ($view) {
            $view->getEngineResolver()->register(
                'blademd',
                fn () => new BladeMarkdownEngine($this->app->get(BladeCompiler::class))
            );
            $view->addExtension('md.blade.php', 'blademd');
        });

        $this->loadViewsFrom(__DIR__ . '/../resources/views/', 'scribe');

        if (! $this->app->runningInConsole()) {
            return;
        }

        // Published in separate, smaller groups so an end user can override one
        // part of the theme without taking a copy of all of it.
        $viewGroups = [
            'views' => '',
            'examples' => 'partials/example-requests',
            'themes' => 'themes',
            'markdown' => 'markdown',
            'external' => 'external',
        ];
        foreach ($viewGroups as $group => $path) {
            $this->publishes([
                __DIR__ . "/../resources/views/{$path}" => $this->app->basePath("resources/views/vendor/scribe/{$path}"),
            ], "scribe-{$group}");
        }
    }
}
