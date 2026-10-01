<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Writing;

use Hypervel\Support\Facades\View;
use InvalidArgumentException;

/**
 * Writes a mostly empty page that hands the OpenAPI spec's URL to a client-side
 * renderer (Scalar, Stoplight Elements or RapiDoc), instead of rendering the
 * endpoints itself the way the bundled themes do.
 *
 * Everything the page shows therefore comes from the spec, fetched by the
 * viewer in the browser: the extracted endpoints are not passed to the view,
 * and none of the theme's own CSS/JS is copied — the viewers load their assets
 * from a CDN.
 */
class ExternalHtmlWriter extends HtmlWriter
{
    /**
     * The themes that name an external viewer, i.e. a `resources/views/external/{theme}.blade.php`.
     *
     * @var string[]
     */
    public const THEMES = ['scalar', 'elements', 'rapidoc'];

    public function generate(array $groupedEndpoints, string $sourceFolder, string $destinationFolder): void
    {
        $template = $this->config->get('theme');
        $metadata = $this->getMetadata();

        // The shipped default theme ('default') has no external view; name that
        // mistake rather than failing with a bare "view not found".
        if (! View::exists("scribe::external.{$template}")) {
            throw new InvalidArgumentException(
                "The '{$this->config->get('type')}' docs type renders the OpenAPI spec through an external viewer, "
                . "but `scribe.theme` is set to '{$template}', which is not one of them. "
                . 'Set it to one of: ' . implode(', ', self::THEMES) . '.'
            );
        }

        $scalarConfig = $this->config->get('external.scalar_config', []);
        $scalarConfig['url'] = $metadata['openapi_spec_url'];

        $output = View::make("scribe::external.{$template}", [
            'metadata' => $metadata,
            'baseUrl' => $this->baseUrl,
            'tryItOut' => $this->config->get('try_it_out'),
            'htmlAttributes' => $this->config->get('external.html_attributes', []),
            'scalarConfig' => json_encode($scalarConfig),
        ])->render();

        if (! is_dir($destinationFolder)) {
            mkdir($destinationFolder, 0o777, true);
        }

        file_put_contents($destinationFolder . '/index.html', $output);
    }

    public function getMetadata(): array
    {
        // These relative paths suit the static types; for the hypervel types Writer
        // turns them into route() calls when those routes are registered.
        if ($this->config->get('postman.enabled', true)) {
            $postmanCollectionUrl = "{$this->assetPathPrefix}collection.json";
        }

        return [
            'title' => $this->config->get('title') ?: config('app.name', '') . ' Documentation',
            'example_languages' => $this->config->get('example_languages'),
            'logo' => $this->config->get('logo') ?? false,
            'last_updated' => $this->getLastUpdated(),
            'try_it_out' => $this->config->get('try_it_out'),
            'postman_collection_url' => $postmanCollectionUrl ?? null,
            // The spec is the viewer's only input, so `Writer` writes it for the
            // external types whether or not `openapi.enabled` is set, and the
            // link is not gated on that flag either.
            'openapi_spec_url' => "{$this->assetPathPrefix}openapi.yaml",
        ];
    }
}
