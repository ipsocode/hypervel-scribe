<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tools;

/**
 * The paths Scribe reads and writes, derived from the config name.
 */
class PathConfig
{
    public function __construct(
        public string $configName = 'scribe',
        // Where Scribe keeps its intermediate output, a sort of cache directory.
        protected ?string $scribeDir = null,
    ) {
        if (is_null($this->scribeDir)) {
            $this->scribeDir = ".{$this->configName}";
        }
    }

    public function outputPath(?string $resolvePath = null, string $separator = '/'): string
    {
        if (is_null($resolvePath)) {
            return $this->configName;
        }

        return "{$this->configName}{$separator}{$resolvePath}";
    }

    public function configFileName(): string
    {
        return "{$this->configName}.php";
    }

    /**
     * The directory where Scribe writes its intermediate output (default .<config>, i.e. .scribe).
     */
    public function intermediateOutputPath(?string $resolvePath = null, string $separator = '/'): string
    {
        if (is_null($resolvePath)) {
            return $this->scribeDir;
        }

        return "{$this->scribeDir}{$separator}{$resolvePath}";
    }
}
