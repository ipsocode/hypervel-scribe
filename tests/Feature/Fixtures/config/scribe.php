<?php

declare(strict_types=1);

/*
 * A deliberately *partial* application config, of the kind someone writes by
 * hand instead of publishing the package's file whole.
 *
 * ScribeConsumerConfigTest points the application's config path here to pin
 * what the provider's merge does with it. Every key below is chosen for what it
 * proves, so add to it rather than editing it:
 *
 * - `title` is a top-level scalar the application overrides.
 * - `auth` names one key of a nested option array, and none of its siblings.
 * - `routes` is a *list*, whose entries are positional rather than named.
 */

return [
    'title' => 'Consumer API',

    'auth' => [
        'enabled' => true,
    ],

    'routes' => [
        [
            'match' => [
                'prefixes' => ['v2/*'],
                'domains' => ['*'],
            ],
        ],
    ],
];
