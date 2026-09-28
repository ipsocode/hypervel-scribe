<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Commands;

use Hypervel\Console\GeneratorCommand;

/**
 * Scaffold a custom extraction strategy.
 *
 * The strategy itself is already a supported extension point — the `Strategy`
 * base class ships here and `config/scribe.php`'s `strategies` array is where a
 * class like this gets registered. This only writes the boilerplate.
 */
class MakeStrategy extends GeneratorCommand
{
    protected ?string $signature = 'scribe:strategy
                            {name : Name of the class.}
                            {--force : Overwrite the file if it already exists}
    ';

    protected string $description = 'Create a new Scribe extraction strategy class.';

    protected string $type = 'Strategy';

    protected function getStub(): string
    {
        return __DIR__ . '/stubs/strategy.stub';
    }

    /**
     * Strategies are documentation tooling rather than application code, so
     * they get their own namespace under the app root instead of landing beside
     * the controllers they read.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return $rootNamespace . '\Docs\Strategies';
    }
}
