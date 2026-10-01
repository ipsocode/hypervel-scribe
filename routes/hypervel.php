<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\Storage;
use Ipsocode\Scribe\Tools\PathConfig;

/*
 * Docs endpoints for the `hypervel` and `external_hypervel` types, loaded by
 * ScribeServiceProvider::bootRoutes() when `scribe.hypervel.add_routes` is on.
 * Route names, view name and storage paths come from PathConfig, the same
 * source Writer uses for the links on the generated page, so the two agree.
 */

$paths = new PathConfig;

$prefix = config('scribe.hypervel.docs_url', '/docs');
$middleware = config('scribe.hypervel.middleware', []);

Route::middleware($middleware)->group(function () use ($paths, $prefix) {
    // The Blade view `scribe:generate` writes (resources/views/scribe/index.blade.php).
    Route::view($prefix, $paths->outputPath('index', '.'))
        ->name($paths->outputPath());

    // The collection and spec live on the 'local' disk, out of the browser's
    // reach, so they are served rather than linked. The handlers capture the
    // paths as strings, not $paths: `route:cache` stores them as serialized
    // closures, which the router unserializes with only its own closure
    // classes allowed, so a captured PathConfig would come back incomplete.
    $collection = $paths->outputPath('collection.json');
    $spec = $paths->outputPath('openapi.yaml');

    Route::get("{$prefix}.postman", fn () => response()->file(
        Storage::disk('local')->path($collection),
        ['Content-Type' => 'application/json'],
    ))->name($paths->outputPath('postman', '.'));

    Route::get("{$prefix}.openapi", fn () => response()->file(
        Storage::disk('local')->path($spec),
    ))->name($paths->outputPath('openapi', '.'));
});
