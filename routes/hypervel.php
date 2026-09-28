<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\Storage;
use Ipsocode\Scribe\Tools\PathConfig;

/*
 * The docs endpoints, for the output types that route the docs through the
 * application (`hypervel` and `external_hypervel`).
 *
 * `ScribeServiceProvider::bootRoutes()` loads this file, and only when
 * `scribe.hypervel.add_routes` is on — an application that would rather do its
 * own routing turns that off and points its own routes at the same view and the
 * same two files.
 *
 * Names, view name and storage paths all come off one `PathConfig`, because the
 * `Writer` builds the links on the generated page from the same source: it
 * rewrites the page's relative `../docs/collection.json` into
 * `{{ route("scribe.postman") }}`. Spell either side by hand and the page ends
 * up linking a route nobody registered.
 */

$paths = new PathConfig;

$prefix = config('scribe.hypervel.docs_url', '/docs');
$middleware = config('scribe.hypervel.middleware', []);

Route::middleware($middleware)->group(function () use ($paths, $prefix) {
    // The page itself, written by `scribe:generate` as a Blade view
    // (resources/views/scribe/index.blade.php).
    Route::view($prefix, $paths->outputPath('index', '.'))
        ->name($paths->outputPath());

    // The collection and the spec live under storage/, which the browser has no
    // way to reach, so they are served rather than linked.
    Route::get("{$prefix}.postman", fn () => response()->file(
        Storage::disk('local')->path($paths->outputPath('collection.json')),
        ['Content-Type' => 'application/json'],
    ))->name($paths->outputPath('postman', '.'));

    Route::get("{$prefix}.openapi", fn () => response()->file(
        Storage::disk('local')->path($paths->outputPath('openapi.yaml')),
    ))->name($paths->outputPath('openapi', '.'));
});
