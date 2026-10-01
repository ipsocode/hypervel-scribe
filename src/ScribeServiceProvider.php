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
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/scribe.php', 'scribe');

        // The route matcher class is read from config inside the closure, so a
        // config change before the first resolution is honored.
        $this->app->bind(
            RouteMatcherInterface::class,
            fn () => $this->app->make(config('scribe.routeMatcher', RouteMatcher::class))
        );
    }

    /**
     * The option arrays whose entries merge by key, so a partial config/scribe.php
     * keeps the package's other defaults inside them. `routes` and `strategies`
     * are replaced whole. See docs/design/config-merging.md.
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

    public function boot(): void
    {
        $this->registerViews();

        $this->bootRoutes();

        // Translations back the extractor's auth and validation rule descriptions.
        $this->loadTranslationsFrom(realpath(__DIR__ . '/../lang') ?: __DIR__ . '/../lang', 'scribe');

        // @codeCoverageIgnoreStart
        // The Blade views use the short `Str` alias. A Hypervel application's alias
        // loader already resolves it, so this only fires for one that dropped it.
        if (! class_exists('Str')) {
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
     * Registers the docs routes for the `hypervel` and `external_hypervel` types,
     * which keep their output outside `public/`; the `static` types need none.
     * `hypervel.add_routes` set to false skips this.
     */
    protected function bootRoutes(): void
    {
        $type = (string) config('scribe.type', 'hypervel');

        if (Str::endsWith($type, 'hypervel') && config('scribe.hypervel.add_routes', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/hypervel.php');
        }
    }

    /**
     * Registers the theme's views and the `blademd` engine for the
     * `*.md.blade.php` example-request partials (Blade first, then Markdown).
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

        // Published in separate groups so an application can override one part
        // of the theme without copying all of it.
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
