<?php

declare(strict_types=1);

/*
 * The package's translated strings, in one group per locale.
 *
 * The framework's FileLoader resolves `{path}/{locale}/{group}.php`, and the
 * group is the first segment of the key — so everything here is reached as
 * `scribe::scribe.<key>`, e.g. `trans('scribe::scribe.auth.details')`.
 *
 * Published to `lang/vendor/scribe/{locale}/scribe.php`, which is where
 * FileLoader::loadNamespaceOverrides() looks, so an application can override a
 * single string without copying the rest.
 *
 * To translate the package, copy this file to `lang/{locale}/scribe.php` in the
 * application (or publish it and translate the copy). Utils::trans() falls back
 * to `en` for any key a locale does not define.
 */

return [
    'labels' => [
        'search' => 'Search',
        'base_url' => 'Base URL',
    ],

    'auth' => [
        'none' => 'This API is not authenticated.',
        'instruction' => [
            'query' => <<<'TEXT'
                To authenticate requests, include a query parameter **`:parameterName`** in the request.
                TEXT,
            'body' => <<<'TEXT'
                To authenticate requests, include a parameter **`:parameterName`** in the body of the request.
                TEXT,
            'query_or_body' => <<<'TEXT'
                To authenticate requests, include a parameter **`:parameterName`** either in the query string or in the request body.
                TEXT,
            'bearer' => <<<'TEXT'
                To authenticate requests, include an **`Authorization`** header with the value **`"Bearer :placeholder"`**.
                TEXT,
            'basic' => <<<'TEXT'
                To authenticate requests, include an **`Authorization`** header in the form **`"Basic {credentials}"`**.
                The value of `{credentials}` should be your username/id and your password, joined with a colon (:),
                and then base64-encoded.
                TEXT,
            'header' => <<<'TEXT'
                To authenticate requests, include a **`:parameterName`** header with the value **`":placeholder"`**.
                TEXT,
        ],
        'details' => <<<'TEXT'
            All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.
            TEXT,
    ],

    'headings' => [
        'introduction' => 'Introduction',
        'auth' => 'Authenticating requests',
    ],

    'endpoint' => [
        'request' => 'Request',
        'headers' => 'Headers',
        'url_parameters' => 'URL Parameters',
        'body_parameters' => 'Body Parameters',
        'query_parameters' => 'Query Parameters',
        'response' => 'Response',
        'response_fields' => 'Response Fields',
        'example_request' => 'Example request',
        'example_response' => 'Example response',
        'responses' => [
            'binary' => 'Binary data',
            'empty' => 'Empty response',
        ],
    ],

    'try_it_out' => [
        'open' => 'Try it out ⚡',
        'cancel' => 'Cancel 🛑',
        'send' => 'Send Request 💥',
        'loading' => '⏱ Sending...',
        'received_response' => 'Received response',
        'request_failed' => 'Request failed with error',
        'error_help' => <<<'TEXT'
            Tip: Check that you're properly connected to the network.
            If you're a maintainer of ths API, verify that your API is running and you've enabled CORS.
            You can check the Dev Tools console for debugging information.
            TEXT,
    ],

    'links' => [
        'postman' => 'View Postman collection',
        'openapi' => 'View OpenAPI spec',
    ],
];
