<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Writing;

use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\Storage;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils as c;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use Ipsocode\Scribe\Tools\Globals;
use Ipsocode\Scribe\Tools\PathConfig;
use Ipsocode\Scribe\Tools\Utils;
use Symfony\Component\Yaml\Yaml;

class Writer
{
    protected bool $isStatic;

    protected bool $isExternal;

    protected ?string $staticTypeOutputPath;

    protected ?string $hypervelTypeOutputPath;

    protected array $generatedFiles = [
        'postman' => null,
        'openapi' => null,
        'html' => null,
        'blade' => null,
        'assets' => [
            'js' => null,
            'css' => null,
            'images' => null,
        ],
    ];

    protected string $hypervelAssetsPath;

    public function __construct(protected DocumentationConfig $config, public PathConfig $paths)
    {
        $this->config->assertTypeIsSupported();

        $this->isStatic = $this->config->outputIsStatic();
        $this->isExternal = $this->config->outputIsExternal();

        $this->hypervelTypeOutputPath = $this->getHypervelTypeOutputPath();
        $this->staticTypeOutputPath = mb_rtrim($this->config->get('static.output_path', 'public/docs'), '/');

        $this->hypervelAssetsPath = $this->config->get('hypervel.assets_directory')
            ? '/' . $this->config->get('hypervel.assets_directory')
            : '/vendor/' . $this->paths->outputPath();
    }

    /**
     * @param array<string,array> $groupedEndpoints
     */
    public function writeDocs(array $groupedEndpoints): void
    {
        // The static assets (js/, css/, and images/) always go in public/docs/.
        // For 'static' docs, the output files (index.html, collection.json) go in public/docs/.
        // For 'hypervel' docs, the output files (index.blade.php, collection.json)
        // go in resources/views/scribe/ and storage/app/scribe/ respectively.
        // For the 'external' types the page is only a shell around a client-side
        // viewer, so the spec it reads has to be written before it.
        if ($this->isExternal) {
            $this->writeOpenAPISpec($groupedEndpoints);
            $this->writePostmanCollection($groupedEndpoints);
            $this->writeExternalHtmlDocs();
        } else {
            $this->writeHtmlDocs($groupedEndpoints);
            $this->writePostmanCollection($groupedEndpoints);
            $this->writeOpenAPISpec($groupedEndpoints);
        }

        $this->runAfterGeneratingHook();
    }

    /**
     * Generate Postman collection JSON file.
     *
     * @param array[] $groupedEndpoints
     */
    public function generatePostmanCollection(array $groupedEndpoints): string
    {
        /** @var PostmanCollectionWriter $writer */
        $writer = app()->makeWith(PostmanCollectionWriter::class, ['config' => $this->config]);

        $collection = $writer->generatePostmanCollection($groupedEndpoints);
        $overrides = $this->config->get('postman.overrides', []);
        if (count($overrides)) {
            foreach ($overrides as $key => $value) {
                data_set($collection, $key, $value);
            }
        }

        return json_encode($collection, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @param array[] $groupedEndpoints
     */
    public function generateOpenAPISpec(array $groupedEndpoints): string
    {
        /** @var OpenAPISpecWriter $writer */
        $writer = app()->makeWith(OpenAPISpecWriter::class, ['config' => $this->config]);
        $spec = $writer->generateSpecContent($groupedEndpoints);

        return Yaml::dump($spec, 20, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP);
    }

    /**
     * @param array[] $groupedEndpoints
     */
    public function writeHtmlDocs(array $groupedEndpoints): void
    {
        if ($this->isStatic) {
            $outputPath = mb_rtrim($this->staticTypeOutputPath, '/') . '/';
            $assetsOutputPath = $outputPath;
        } else {
            $outputPath = mb_rtrim($this->hypervelTypeOutputPath, '/') . '/';
            $assetsOutputPath = public_path() . $this->hypervelAssetsPath . '/';
        }
        c::task(
            'Writing ' . ($this->isStatic ? 'HTML' : 'Blade') . ' docs to ' . $this->makePathFriendly($outputPath) . ' and assets to ' . $this->makePathFriendly($assetsOutputPath),
            function () use ($assetsOutputPath, $outputPath, $groupedEndpoints) {
                // Then we convert them to HTML, and throw in the endpoints as well.
                /** @var HtmlWriter $writer */
                $writer = app()->makeWith(HtmlWriter::class, ['config' => $this->config]);
                $writer->generate($groupedEndpoints, $this->paths->intermediateOutputPath(), $this->staticTypeOutputPath);

                if (! $this->isStatic) {
                    $this->performFinalTasksForHypervelType();
                }

                if ($this->isStatic) {
                    $this->generatedFiles['html'] = realpath("{$outputPath}index.html");
                } else {
                    $this->generatedFiles['blade'] = realpath("{$outputPath}index.blade.php");
                }
                $this->generatedFiles['assets']['js'] = realpath("{$assetsOutputPath}js");
                $this->generatedFiles['assets']['css'] = realpath("{$assetsOutputPath}css");
                $this->generatedFiles['assets']['images'] = realpath("{$assetsOutputPath}images");

                return true;
            }
        );
    }

    /**
     * Write the shell page that hands the OpenAPI spec to a client-side viewer.
     *
     * No endpoints and no assets: the viewer fetches the spec in the browser and
     * loads its own CSS/JS from a CDN, so the only file written is the page.
     */
    public function writeExternalHtmlDocs(): void
    {
        $outputPath = mb_rtrim($this->isStatic ? $this->staticTypeOutputPath : $this->hypervelTypeOutputPath, '/') . '/';

        c::task(
            'Writing client-side HTML docs to ' . $this->makePathFriendly($outputPath),
            function () use ($outputPath) {
                /** @var ExternalHtmlWriter $writer */
                $writer = app()->makeWith(ExternalHtmlWriter::class, ['config' => $this->config]);
                $writer->generate([], $this->paths->intermediateOutputPath(), $this->staticTypeOutputPath);

                if ($this->isStatic) {
                    $this->generatedFiles['html'] = realpath("{$outputPath}index.html");

                    return true;
                }

                $this->performFinalTasksForHypervelType();
                $this->generatedFiles['blade'] = realpath("{$outputPath}index.blade.php");

                return true;
            }
        );

        // The spec lands in storage/ for the hypervel types, where the browser
        // cannot reach it, so the page links it by route name instead. The
        // package registers that route itself unless `hypervel.add_routes` is
        // off; without it the viewer loads and then has nothing to render,
        // which is worth saying out loud.
        $routeName = $this->paths->outputPath('openapi', '.');
        if (! $this->isStatic && ! Route::has($routeName)) {
            c::warn(
                "The docs page fetches the OpenAPI spec over HTTP, but no route named '{$routeName}' is registered, "
                . 'so it points at a path that does not exist. Turn on `scribe.hypervel.add_routes`, register a '
                . 'route of your own serving '
                . Storage::disk('local')->path($this->paths->outputPath('openapi.yaml'))
                . " under that name, or use the 'external_static' type."
            );
        }
    }

    protected function writePostmanCollection(array $groups): void
    {
        if ($this->config->get('postman.enabled', true)) {
            $outputPath = $this->isStatic ? $this->staticTypeOutputPath : Storage::disk('local')->path($this->paths->outputPath());
            c::task(
                'Generating Postman collection in ' . mb_rtrim($this->makePathFriendly($outputPath), '/') . '/',
                function () use ($groups) {
                    $collection = $this->generatePostmanCollection($groups);
                    if ($this->isStatic) {
                        $collectionPath = "{$this->staticTypeOutputPath}/collection.json";
                        file_put_contents($collectionPath, $collection);
                    } else {
                        $outputPath = $this->paths->outputPath('collection.json');
                        Storage::disk('local')->put($outputPath, $collection);
                        $collectionPath = Storage::disk('local')->path($outputPath);
                    }

                    $this->generatedFiles['postman'] = realpath($collectionPath);

                    return true;
                }
            );
        }
    }

    protected function writeOpenAPISpec(array $parsedRoutes): void
    {
        if ($this->config->get('openapi.enabled', false) || $this->isExternal) {
            $outputPath = $this->isStatic ? $this->staticTypeOutputPath : Storage::disk('local')->path($this->paths->outputPath());
            c::task(
                'Generating OpenAPI specification in ' . mb_rtrim($this->makePathFriendly($outputPath), '/') . '/',
                function () use ($parsedRoutes) {
                    $spec = $this->generateOpenAPISpec($parsedRoutes);
                    if ($this->isStatic) {
                        Utils::makeDirectoryRecursive($this->staticTypeOutputPath);
                        $specPath = "{$this->staticTypeOutputPath}/openapi.yaml";
                        file_put_contents($specPath, $spec);
                    } else {
                        $outputPath = $this->paths->outputPath('openapi.yaml');
                        Storage::disk('local')->put($outputPath, $spec);
                        $specPath = Storage::disk('local')->path($outputPath);
                    }

                    $this->generatedFiles['openapi'] = realpath($specPath);

                    return true;
                }
            );
        }
    }

    /**
     * Turn the rendered HTML into a Blade view and move the assets out of public/docs.
     *
     * Upstream rewrites the spec/collection links to `{{ route(...) }}`
     * unconditionally. The routes those names belong to are only registered when
     * `hypervel.add_routes` is on, and a `route()` call for a name nobody
     * registered throws when the view renders — so the link is only rewritten
     * when the route actually exists. Routes are registered at boot, in the same
     * process that runs `scribe:generate`, so what is true here is true at
     * render time.
     */
    protected function performFinalTasksForHypervelType(): void
    {
        if (! is_dir($this->hypervelTypeOutputPath)) {
            mkdir($this->hypervelTypeOutputPath, 0o777, true);
        }
        $publicDirectory = public_path();
        if (! is_dir($publicDirectory . $this->hypervelAssetsPath)) {
            mkdir($publicDirectory . $this->hypervelAssetsPath, 0o777, true);
        }

        // Transform output HTML to a Blade view
        rename("{$this->staticTypeOutputPath}/index.html", "{$this->hypervelTypeOutputPath}/index.blade.php");

        // Move assets from public/docs to public/vendor/scribe or config('hypervel.assets_directory').
        // We need to do this delete first, otherwise the move won't work if the folder exists.
        // Upstream renames the directory. Copy-then-delete instead, because
        // rename() cannot move a directory across filesystems and `public` is not
        // always on the same one as the working directory.
        Utils::deleteDirectoryAndContents($publicDirectory . $this->hypervelAssetsPath);
        Utils::copyDirectory($this->staticTypeOutputPath, $publicDirectory . $this->hypervelAssetsPath);
        Utils::deleteDirectoryAndContents($this->staticTypeOutputPath);

        $contents = file_get_contents("{$this->hypervelTypeOutputPath}/index.blade.php");

        // Rewrite asset links to go through the app
        $contents = preg_replace('#href="\.\./docs/css/(.+?)"#', 'href="{{ asset("' . $this->hypervelAssetsPath . '/css/$1") }}"', $contents);
        $contents = preg_replace('#src="\.\./docs/(js|images)/(.+?)"#', 'src="{{ asset("' . $this->hypervelAssetsPath . '/$1/$2") }}"', $contents);

        foreach (['postman' => 'collection.json', 'openapi' => 'openapi.yaml'] as $name => $file) {
            $routeName = $this->paths->outputPath($name, '.');
            if (! Route::has($routeName)) {
                continue;
            }

            $route = '{{ route("' . $routeName . '") }}';
            // The theme links both files with an href. The external viewers take
            // the spec's URL as an attribute instead — `spec-url` (RapiDoc),
            // `apiDescriptionUrl` (Elements) — or inside Scalar's config, which
            // is JSON, so json_encode has escaped its slashes.
            $contents = str_replace([
                "href=\"../docs/{$file}\"",
                "url=\"../docs/{$file}\"",
                "Url=\"../docs/{$file}\"",
                '..\/docs\/' . $file,
            ], [
                "href=\"{$route}\"",
                "url=\"{$route}\"",
                "Url=\"{$route}\"",
                $route,
            ], $contents);
        }

        file_put_contents("{$this->hypervelTypeOutputPath}/index.blade.php", $contents);
    }

    protected function runAfterGeneratingHook()
    {
        if (is_callable(Globals::$__afterGenerating)) {
            c::info('Running `afterGenerating()` hook...');
            call_user_func_array(Globals::$__afterGenerating, [$this->generatedFiles]);
        }
    }

    protected function getHypervelTypeOutputPath(): ?string
    {
        if ($this->isStatic) {
            return null;
        }

        return config(
            'view.paths.0',
            function_exists('base_path') ? base_path('resources/views') : 'resources/views'
        ) . '/' . $this->paths->outputPath();
    }

    /**
     * Turn a path from (possibly) C:\projects\myapp\resources\views
     * or /projects/myapp/resources/views  to resources/views ie:
     * - make it relative to PWD
     * - normalise all slashes to forward slashes.
     */
    protected function makePathFriendly(string $path): string
    {
        return str_replace('\\', '/', str_replace(getcwd() . DIRECTORY_SEPARATOR, '', $path));
    }
}
