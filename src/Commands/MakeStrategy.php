<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Commands;

use Hypervel\Console\GeneratorCommand;

/**
 * Scaffolds a custom extraction strategy, to be registered in the `strategies`
 * array of `config/scribe.php`.
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
     * Strategies are documentation tooling, so they get their own namespace
     * rather than sitting beside the controllers they read.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return $rootNamespace . '\Docs\Strategies';
    }
}
