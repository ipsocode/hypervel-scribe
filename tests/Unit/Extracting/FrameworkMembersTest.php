<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit\Extracting;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Hypervel\Routing\UrlGenerator;
use Hypervel\Validation\ClosureValidationRule;
use Hypervel\Validation\Rules\Enum;
use Hypervel\Validation\Validator;
use Ipsocode\Scribe\Commands\GenerateDocumentation;
use Ipsocode\Scribe\Extracting\GeneratesRuleExamples;
use Ipsocode\Scribe\Extracting\ParsesValidationRules;
use Ipsocode\Scribe\Extracting\Strategies\GetFromFormRequestBase;
use Ipsocode\Scribe\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Extraction reads members Hypervel does not expose, by reflection.
 *
 * A rename in `hypervel/components` would otherwise break extraction somewhere
 * downstream, so each read has a test that names the member it needs.
 */
class FrameworkMembersTest extends TestCase
{
    /**
     * @param class-string $class
     * @param class-string $reader
     */
    #[Test]
    #[UnitTest]
    #[DataProvider('properties')]
    public function theFrameworkClassStillHasTheReflectedProperty(string $class, string $property, string $reader): void
    {
        $this->assertTrue(
            (new ReflectionClass($class))->hasProperty($property),
            "{$class} no longer has \${$property}, which {$reader} reads.",
        );
    }

    /**
     * @return array<string, array{class-string, string, class-string}>
     */
    public static function properties(): array
    {
        return [
            'Validator::$initialRules' => [Validator::class, 'initialRules', GetFromFormRequestBase::class],
            'ClosureValidationRule::$callback' => [ClosureValidationRule::class, 'callback', ParsesValidationRules::class],
            'Enum::$only' => [Enum::class, 'only', GeneratesRuleExamples::class],
            'Enum::$except' => [Enum::class, 'except', GeneratesRuleExamples::class],
            'Enum::$type' => [Enum::class, 'type', GeneratesRuleExamples::class],
        ];
    }

    #[Test]
    #[UnitTest]
    public function theUrlGeneratorStillHasTheForcedRootContextKey(): void
    {
        $this->assertTrue(
            (new ReflectionClass(UrlGenerator::class))->hasConstant('FORCED_ROOT_CONTEXT_KEY'),
            UrlGenerator::class . ' no longer has FORCED_ROOT_CONTEXT_KEY, which ' . GenerateDocumentation::class . ' reads.',
        );
    }
}
