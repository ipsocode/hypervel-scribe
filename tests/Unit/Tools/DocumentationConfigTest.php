<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit\Tools;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use InvalidArgumentException;
use Ipsocode\Scribe\Tests\Unit\TestCase;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class DocumentationConfigTest extends TestCase
{
    #[UnitTest]
    #[Test]
    public function readsNestedValuesWithDotNotation(): void
    {
        $config = new DocumentationConfig(['auth' => ['in' => 'bearer']]);

        $this->assertSame('bearer', $config->get('auth.in'));
    }

    #[UnitTest]
    #[Test]
    public function returnsTheDefaultForAMissingKey(): void
    {
        $config = new DocumentationConfig([]);

        $this->assertNull($config->get('auth.in'));
        $this->assertSame('fallback', $config->get('auth.in', 'fallback'));
    }

    /**
     * @param 'external_hypervel'|'external_static'|'hypervel'|'static' $type
     */
    #[UnitTest]
    #[Test]
    #[DataProvider('outputTypes')]
    public function classifiesTheOutputType(string $type, bool $static, bool $throughApp, bool $external): void
    {
        // These three predicates decide which writer runs and where the output
        // lands, and they are not mutually exclusive — `external_hypervel` is
        // both routed through the framework and external.
        $config = new DocumentationConfig(['type' => $type]);

        $this->assertSame($static, $config->outputIsStatic());
        $this->assertSame($throughApp, $config->outputRoutedThroughApp());
        $this->assertSame($external, $config->outputIsExternal());
    }

    /**
     * @return array<string, array{string, bool, bool, bool}>
     */
    public static function outputTypes(): array
    {
        return [
            //                       type                  static  routed  external
            'hypervel' => ['hypervel', false, true, false],
            'static' => ['static', true, false, false],
            'external_hypervel' => ['external_hypervel', false, true, true],
            'external_static' => ['external_static', true, false, true],
        ];
    }

    #[UnitTest]
    #[Test]
    public function everySupportedTypePassesTheCheck(): void
    {
        foreach (DocumentationConfig::TYPES as $type) {
            (new DocumentationConfig(['type' => $type]))->assertTypeIsSupported();
        }

        // Reached only because none of them threw. Pinning the list here as
        // well keeps the rejection message below honest.
        $this->assertSame(['static', 'hypervel', 'external_static', 'external_hypervel'], DocumentationConfig::TYPES);
    }

    #[UnitTest]
    #[Test]
    public function anUnknownTypeIsRejectedWithTheListOfRealOnes(): void
    {
        // The classification above splits the known types two ways rather than
        // matching them, so an unknown one does not fall out of it — it falls
        // into 'static' and quietly writes to public/docs.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "`type` is set to 'blade', which is not an output type Scribe knows. "
            . 'Set it to one of: static, hypervel, external_static, external_hypervel.'
        );

        (new DocumentationConfig(['type' => 'blade']))->assertTypeIsSupported();
    }

    #[UnitTest]
    #[Test]
    #[DataProvider('renamedOutputTypes')]
    public function upstreamsOwnTypeNamesSayWhatTheyBecame(string $type, string $renamedTo): void
    {
        // The likeliest wrong value by far: it is what a config file copied
        // from knuckleswtf/scribe carries.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("This port renames upstream's '{$type}' to '{$renamedTo}'.");

        (new DocumentationConfig(['type' => $type]))->assertTypeIsSupported();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function renamedOutputTypes(): array
    {
        return [
            'laravel' => ['laravel', 'hypervel'],
            'external_laravel' => ['external_laravel', 'external_hypervel'],
        ];
    }

    #[UnitTest]
    #[Test]
    public function aMissingTypeIsRejectedByName(): void
    {
        // `type` is not always a string when it is wrong — an unset key reads
        // back as null, which has no quotes to print.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('`type` is set to null, which is not an output type Scribe knows.');

        (new DocumentationConfig([]))->assertTypeIsSupported();
    }
}
