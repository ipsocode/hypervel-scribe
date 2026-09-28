<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit\Tools;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Scribe\Tests\Unit\TestCase;
use Ipsocode\Scribe\Tools\PathConfig;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every path Scribe writes to is derived here, and the `--config` option lets an
 * application run two documented APIs side by side. Getting the derivation wrong
 * means one config's output silently overwrites the other's.
 */
class PathConfigTest extends TestCase
{
    #[UnitTest]
    #[Test]
    public function defaultsTheOutputAndIntermediatePathsToTheConfigName(): void
    {
        $paths = new PathConfig;

        $this->assertSame('scribe', $paths->outputPath());
        $this->assertSame('scribe.php', $paths->configFileName());
        $this->assertSame('.scribe', $paths->intermediateOutputPath());
    }

    #[UnitTest]
    #[Test]
    public function aSecondConfigGetsItsOwnOutputAndCacheDirectories(): void
    {
        $paths = new PathConfig('scribe_admin');

        $this->assertSame('scribe_admin', $paths->outputPath());
        $this->assertSame('scribe_admin.php', $paths->configFileName());
        $this->assertSame('.scribe_admin', $paths->intermediateOutputPath());
    }

    #[UnitTest]
    #[Test]
    public function theIntermediateDirectoryCanBeOverriddenIndependently(): void
    {
        $paths = new PathConfig('scribe', '/tmp/scribe-cache');

        $this->assertSame('/tmp/scribe-cache', $paths->intermediateOutputPath());
        // Overriding the cache directory must not move the output directory.
        $this->assertSame('scribe', $paths->outputPath());
    }

    #[UnitTest]
    #[Test]
    public function resolvesChildPathsWithTheRequestedSeparator(): void
    {
        $paths = new PathConfig;

        $this->assertSame('scribe/index.html', $paths->outputPath('index.html'));
        $this->assertSame('scribe.index', $paths->outputPath('index', '.'));
        $this->assertSame('.scribe/endpoints', $paths->intermediateOutputPath('endpoints'));
        $this->assertSame('.scribe.endpoints', $paths->intermediateOutputPath('endpoints', '.'));
    }
}
