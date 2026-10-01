<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tools;

use Hypervel\Filesystem\Filesystem;
use Hypervel\View\Compilers\CompilerInterface;
use Hypervel\View\Engines\CompilerEngine;
use Parsedown;

/**
 * The `blademd` view engine: compiles a view with Blade, then runs the result
 * through Markdown.
 *
 * It backs the `*.md.blade.php` example-request partials, which are written as
 * Markdown (fenced code blocks and all) but need Blade's loops and escaping to
 * build the request bodies. Registered on the view engine resolver in
 * ScribeServiceProvider::registerViews().
 */
class BladeMarkdownEngine extends CompilerEngine
{
    private Parsedown $markdown;

    public function __construct(CompilerInterface $compiler, ?Filesystem $files = null)
    {
        parent::__construct($compiler, $files ?: new Filesystem);

        $this->markdown = Parsedown::instance();
    }

    public function get(string $path, array $data = []): string
    {
        return $this->markdown->text(parent::get($path, $data));
    }
}
