<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Writing;

use Hypervel\Support\Facades\Blade;
use Hypervel\Support\Facades\View;
use Hypervel\Support\Str;
use InvalidArgumentException;
use Ipsocode\Camel\Output\OutputEndpointData;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use Ipsocode\Scribe\Tools\MarkdownParser;
use Ipsocode\Scribe\Tools\Utils;
use Ipsocode\Scribe\Tools\WritingUtils;
use SplFileInfo;

/**
 * Transforms the extracted data (endpoints YAML, API details Markdown) into an HTML site.
 */
class HtmlWriter
{
    protected DocumentationConfig $config;

    protected string $baseUrl;

    protected string $assetPathPrefix;

    protected MarkdownParser $markdownParser;

    public function __construct(?DocumentationConfig $config = null)
    {
        $this->config = $config ?: new DocumentationConfig(config('scribe', []));
        $this->markdownParser = new MarkdownParser;
        $baseUrl = $this->config->get('base_url') ?? config('app.url');
        $this->baseUrl = Str::contains($baseUrl, ['{{', '{!!', '@']) ? Blade::render($baseUrl) : $baseUrl;
        // '../docs/{asset}' works both through the app and from the index.html
        // file in the default public/docs, and Writer rewrites it for the hypervel
        // types. A static type written anywhere else links its assets relatively.
        $this->assetPathPrefix = '../docs/';
        if (in_array($this->config->get('type'), ['static', 'external_static'])
            && mb_rtrim($this->config->get('static.output_path', ''), '/') !== 'public/docs'
        ) {
            $this->assetPathPrefix = './';
        }
    }

    public function generate(array $groupedEndpoints, string $sourceFolder, string $destinationFolder): void
    {
        $intro = $this->transformMarkdownFileToHTML($sourceFolder . '/intro.md');
        $auth = $this->transformMarkdownFileToHTML($sourceFolder . '/auth.md');
        $headingsBeforeEndpoints = $this->markdownParser->headings;

        $this->markdownParser->headings = [];
        $append = $this->transformMarkdownFileToHTML(mb_rtrim($sourceFolder, '/') . '/append.md');
        $headingsAfterEndpoints = $this->markdownParser->headings;

        foreach ($groupedEndpoints as &$group) {
            $group['subgroups'] = collect($group['endpoints'])->groupBy('metadata.subgroup')->all();
        }
        unset($group);

        $theme = $this->config->get('theme') ?? 'default';
        $output = View::make("scribe::themes.{$theme}.index", [
            'metadata' => $this->getMetadata(),
            'baseUrl' => $this->baseUrl,
            'tryItOut' => $this->config->get('try_it_out'),
            'intro' => $intro,
            'auth' => $auth,
            'groupedEndpoints' => $groupedEndpoints,
            'headings' => $this->getHeadings($headingsBeforeEndpoints, $groupedEndpoints, $headingsAfterEndpoints),
            'append' => $append,
            'assetPathPrefix' => $this->assetPathPrefix,
        ])->render();

        if (! is_dir($destinationFolder)) {
            mkdir($destinationFolder, 0o777, true);
        }

        file_put_contents($destinationFolder . '/index.html', $output);

        $assetsFolder = __DIR__ . '/../../resources';
        static::pruneThemeAssets($destinationFolder);
        Utils::copyDirectory("{$assetsFolder}/images/", "{$destinationFolder}/images");

        $assets = [
            "{$assetsFolder}/css/theme-{$theme}.style.css" => ["{$destinationFolder}/css/", "theme-{$theme}.style.css"],
            "{$assetsFolder}/css/theme-{$theme}.print.css" => ["{$destinationFolder}/css/", "theme-{$theme}.print.css"],
            "{$assetsFolder}/js/theme-{$theme}.js" => ["{$destinationFolder}/js/", WritingUtils::getVersionedAsset("theme-{$theme}.js")],
        ];

        if ($this->config->get('try_it_out.enabled', true)) {
            $assets["{$assetsFolder}/js/tryitout.js"] = ["{$destinationFolder}/js/", WritingUtils::getVersionedAsset('tryitout.js')];
        }

        foreach ($assets as $path => [$destination, $fileName]) {
            if (file_exists($path)) {
                if (! is_dir($destination)) {
                    mkdir($destination, 0o777, true);
                }
                copy($path, $destination . $fileName);
            }
        }
    }

    /**
     * Delete the theme files an earlier run wrote (versioned ones included),
     * leaving anything else in the directory alone.
     */
    public static function pruneThemeAssets(string $directory): void
    {
        Utils::deleteFilesMatching("{$directory}/css", fn (SplFileInfo $file) => Str::startsWith($file->getFilename(), 'theme-'));
        Utils::deleteFilesMatching(
            "{$directory}/js",
            fn (SplFileInfo $file) => Str::startsWith($file->getFilename(), ['theme-', 'tryitout']),
        );
    }

    public function getMetadata(): array
    {
        // These relative paths suit the static types; for the hypervel types Writer
        // turns them into route() calls when those routes are registered.
        if ($this->config->get('postman.enabled', true)) {
            $postmanCollectionUrl = "{$this->assetPathPrefix}collection.json";
        }
        if ($this->config->get('openapi.enabled', false)) {
            $openApiSpecUrl = "{$this->assetPathPrefix}openapi.yaml";
        }

        $auth = $this->config->get('auth');
        if ($auth) {
            if ($auth['in'] === 'bearer' || $auth['in'] === 'basic') {
                $auth['name'] = 'Authorization';
                $auth['location'] = 'header';
                $auth['prefix'] = ucfirst($auth['in']) . ' ';
            } else {
                $auth['location'] = $auth['in'];
                $auth['prefix'] = '';
            }
        }

        return [
            'title' => $this->config->get('title') ?: config('app.name', '') . ' Documentation',
            'example_languages' => $this->config->get('example_languages'),
            'logo' => $this->config->get('logo') ?? false,
            'last_updated' => $this->getLastUpdated(),
            'auth' => $auth,
            'try_it_out' => $this->config->get('try_it_out'),
            'postman_collection_url' => $postmanCollectionUrl ?? null,
            'openapi_spec_url' => $openApiSpecUrl ?? null,
        ];
    }

    /**
     * Render a Markdown file to HTML, recording its headings on the parser.
     *
     * A missing file renders as nothing rather than fataling. `append.md` is
     * the user's own and optional; `intro.md` and `auth.md` come from
     * ApiDetails, which only runs during extraction, so a `--no-extraction` run
     * against an intermediate directory without them finds neither.
     */
    protected function transformMarkdownFileToHTML(string $markdownFilePath): string
    {
        if (! file_exists($markdownFilePath)) {
            return '';
        }

        return $this->markdownParser->text(file_get_contents($markdownFilePath));
    }

    protected function getLastUpdated(): string
    {
        $lastUpdated = $this->config->get('last_updated', 'Last updated: {date:F j, Y}');

        $tokens = [
            'date' => fn ($format) => date($format),
            'git' => fn ($format) => match ($format) {
                'short' => mb_substr($this->gitHead(), 0, 7),
                'long' => $this->gitHead(),
                default => throw new InvalidArgumentException("The `git` token only supports formats 'short' and 'long', but you specified {$format}"),
            },
        ];

        foreach ($tokens as $token => $resolver) {
            $matches = [];
            if (preg_match('#(\{' . $token . ':(.+?)})#', $lastUpdated, $matches)) {
                $lastUpdated = str_replace($matches[1], $resolver($matches[2]), $lastUpdated);
            }
        }

        return $lastUpdated;
    }

    /**
     * The commit HEAD points at, read from the .git directory rather than by
     * running git, which would block the worker. Like git, it looks for .git
     * in the base path and then in each directory above it. Empty outside a
     * repository.
     */
    protected function gitHead(): string
    {
        $workTree = base_path();
        while (! file_exists($workTree . '/.git') && dirname($workTree) !== $workTree) {
            $workTree = dirname($workTree);
        }
        $gitDir = $workTree . '/.git';

        // In a worktree or a submodule, .git is a file naming the git directory.
        if (is_file($gitDir) && preg_match('/^gitdir: (.+)$/m', (string) file_get_contents($gitDir), $matches)) {
            $gitDir = $this->gitPath($workTree, mb_trim($matches[1]));
        }

        // A worktree keeps its own HEAD, but its branches are the main repository's.
        $refsDir = is_file($gitDir . '/commondir')
            ? $this->gitPath($gitDir, mb_trim((string) file_get_contents($gitDir . '/commondir')))
            : $gitDir;

        $head = is_file($gitDir . '/HEAD') ? mb_trim((string) file_get_contents($gitDir . '/HEAD')) : '';

        if (! str_starts_with($head, 'ref: ')) {
            return $head;
        }

        $ref = mb_substr($head, 5);
        if (is_file($refsDir . '/' . $ref)) {
            return mb_trim((string) file_get_contents($refsDir . '/' . $ref));
        }

        $packed = is_file($refsDir . '/packed-refs') ? (string) file_get_contents($refsDir . '/packed-refs') : '';

        return preg_match('/^([0-9a-f]{40}) ' . preg_quote($ref, '/') . '$/m', $packed, $matches) ? $matches[1] : '';
    }

    /**
     * A path from a git pointer file, which is absolute or relative to the given directory.
     */
    protected function gitPath(string $relativeTo, string $path): string
    {
        return str_starts_with($path, '/') ? $path : "{$relativeTo}/{$path}";
    }

    protected function getHeadings(array $headingsBeforeEndpoints, array $endpointsByGroupAndSubgroup, array $headingsAfterEndpoints): array
    {
        $headings = [];

        $lastL1ElementIndex = null;
        foreach ($headingsBeforeEndpoints as $heading) {
            $element = [
                'slug' => $heading['slug'],
                'name' => $heading['text'],
                'subheadings' => [],
            ];
            if ($heading['level'] === 1) {
                $headings[] = $element;
                $lastL1ElementIndex = count($headings) - 1;
            } elseif ($heading['level'] === 2 && ! is_null($lastL1ElementIndex)) {
                $headings[$lastL1ElementIndex]['subheadings'][] = $element;
            }
        }

        $headings = array_merge($headings, array_values(array_map(function ($group) {
            $groupSlug = Str::slug($group['name']);

            return [
                'slug' => $groupSlug,
                'name' => $group['name'],
                'subheadings' => collect($group['subgroups'])->flatMap(function ($endpoints, $subgroupName) use ($groupSlug) {
                    if ($subgroupName === '') {
                        return $endpoints->map(fn (OutputEndpointData $endpoint) => [
                            'slug' => $endpoint->fullSlug(),
                            'name' => $endpoint->name(),
                            'subheadings' => [],
                        ])->values();
                    }

                    return [
                        [
                            'slug' => "{$groupSlug}-" . Str::slug($subgroupName),
                            'name' => $subgroupName,
                            'subheadings' => $endpoints->map(fn ($endpoint) => [
                                'slug' => $endpoint->fullSlug(),
                                'name' => $endpoint->name(),
                                'subheadings' => [],
                            ])->values(),
                        ],
                    ];
                })->values(),
            ];
        }, $endpointsByGroupAndSubgroup)));

        $lastL1ElementIndex = null;
        foreach ($headingsAfterEndpoints as $heading) {
            $element = [
                'slug' => $heading['slug'],
                'name' => $heading['text'],
                'subheadings' => [],
            ];
            if ($heading['level'] === 1) {
                $headings[] = $element;
                $lastL1ElementIndex = count($headings) - 1;
            } elseif ($heading['level'] === 2 && ! is_null($lastL1ElementIndex)) {
                $headings[$lastL1ElementIndex]['subheadings'][] = $element;
            }
        }

        return $headings;
    }
}
