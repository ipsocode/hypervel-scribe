<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Hypervel\Routing\Controller;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;

/**
 * A base controller that documents the methods its children inherit.
 *
 * An action defined on a base class has one docblock however many controllers
 * extend it, so there is nowhere to say what differs between them.
 * `inheritedDocsOverrides()` is that place: keyed by method name, then by
 * extraction stage, and holding either the values themselves or a callback that
 * works them out from the endpoint.
 *
 * Registered by InheritedDocsTest rather than in the Workbench route file, so
 * the documented API the writer tests assert against is unchanged.
 *
 * @group Inherited docs
 */
class InheritedDocsController extends Controller
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function inheritedDocsOverrides(): array
    {
        return [
            'listed' => [
                // A stage with no case of its own: taken as-is.
                'headers' => ['X-Inherited' => 'from the base'],
                'queryParameters' => [
                    'inherited' => [
                        'type' => 'string',
                        'description' => 'Declared on the base controller.',
                        'example' => 'yes',
                        'required' => true,
                    ],
                ],
                'responseFields' => [
                    'ok' => ['type' => 'boolean', 'description' => 'Always true.'],
                ],
                'responses' => [
                    ['status' => 201, 'content' => '{"ok":true}', 'description' => 'Created'],
                ],
            ],
            'computed' => [
                // The same four stages, worked out from the endpoint instead.
                'headers' => fn (ExtractedEndpointData $endpointData) => ['X-Endpoint' => $endpointData->uri],
                'bodyParameters' => fn () => [
                    'computed' => [
                        'type' => 'string',
                        'description' => 'Worked out at extraction time.',
                        'example' => 'now',
                    ],
                ],
                'responseFields' => fn () => [
                    'ok' => ['type' => 'boolean', 'description' => 'Always true.'],
                ],
                'responses' => fn () => [
                    ['status' => 202, 'content' => '{"ok":true}', 'description' => 'Accepted'],
                ],
            ],
        ];
    }

    /**
     * An endpoint documented from a list of values on the base controller.
     */
    public function listed(): array
    {
        return [];
    }

    /**
     * An endpoint documented by callbacks on the base controller.
     */
    public function computed(): array
    {
        return [];
    }
}
