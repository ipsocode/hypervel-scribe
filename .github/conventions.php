<?php

declare(strict_types=1);

/*
 * This package's settings for the conventions check, .github/scripts/conventions.php,
 * which initial.yml runs ahead of the test jobs and `composer conventions` runs locally.
 * The check is imported, the same in every ipsocode/hypervel-* package; this file is
 * the part that is this package's own. The script's header lists the rules.
 */

return [
    // The name this package writes under wherever the application writes too:
    // __scribe.* context keys, scribe:* commands and store keys, scribe-* publish
    // tags, SCRIBE_* env vars and config/scribe.php.
    'slug' => 'scribe',

    // Paths under the package root that no rule governs.
    'excluded' => [],

    // Namespaces banned on top of Illuminate\ and Laravel\, each with what to use
    // instead.
    'banned_namespaces' => [],

    // Function-name prefixes banned in shipped code, each with what to use instead.
    'banned_functions' => [],

    // Paths phpunit.xml's <source> may leave out of the coverage gate.
    'coverage_excludes' => [],

    // The exceptions, per rule: '<path>' => [<exact number of hits>, '<why>']. The
    // check fails as soon as a count stops matching, either way. Each reason is the
    // one the code's own comment gives.
    'allowed' => [
        'namespace' => [
            'src/Tools/Utils.php' => [5, "laravel/serializable-closure is the library Hypervel's Route uses to store a closure route under route:cache, and the only way to read one back"],
            'tests/Feature/Tools/UtilsTest.php' => [1, 'builds the serialized closure that route:cache would have stored'],
        ],
        'eval-unserialize' => [
            'src/Tools/Utils.php' => [1, "reads a closure route back from route:cache the way Route::runCallable() does, with allowed_classes limited to the serializable-closure classes"],
        ],
    ],
];
