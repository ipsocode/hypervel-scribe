<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit\Exceptions;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Scribe\Exceptions\CouldntFindFactory;
use Ipsocode\Scribe\Exceptions\CouldntGetRouteDetails;
use Ipsocode\Scribe\Exceptions\CouldntProcessValidationRule;
use Ipsocode\Scribe\Exceptions\CouldntStartDatabaseTransaction;
use Ipsocode\Scribe\Exceptions\DatabaseTransactionsNotSupported;
use Ipsocode\Scribe\Exceptions\GroupNotFound;
use Ipsocode\Scribe\Exceptions\ProblemParsingValidationRules;
use Ipsocode\Scribe\Exceptions\ScribeException;
use Ipsocode\Scribe\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * These exceptions are the package's user-facing error messages: generation
 * catches and swallows most failures, and only ScribeException implementors are
 * meant to escape. Two things matter and are asserted for each — that it is a
 * ScribeException (so it is not swallowed) and that its message names the thing
 * the user has to go and fix.
 */
class ScribeExceptionsTest extends TestCase
{
    #[UnitTest]
    #[Test]
    public function couldntFindFactoryNamesTheModelAndTheMissingTrait(): void
    {
        $e = CouldntFindFactory::forModel('App\Models\User');

        $this->assertInstanceOf(ScribeException::class, $e);
        $this->assertInstanceOf(RuntimeException::class, $e);
        $this->assertStringContainsString('App\Models\User', $e->getMessage());
        $this->assertStringContainsString('HasFactory', $e->getMessage());
    }

    #[UnitTest]
    #[Test]
    public function couldntGetRouteDetailsSuggestsClearingTheRouteCache(): void
    {
        $e = CouldntGetRouteDetails::new();

        $this->assertInstanceOf(ScribeException::class, $e);
        $this->assertStringContainsString('route:clear', $e->getMessage());
    }

    #[UnitTest]
    #[Test]
    public function couldntProcessValidationRuleKeepsTheOriginalException(): void
    {
        $inner = new RuntimeException('unsupported rule');

        $e = CouldntProcessValidationRule::forParam('email', 'weird_rule', $inner);

        $this->assertInstanceOf(ScribeException::class, $e);
        $this->assertStringContainsString('email', $e->getMessage());
        $this->assertStringContainsString("'weird_rule'", $e->getMessage());
        $this->assertStringContainsString('unsupported rule', $e->getMessage());
        // Losing the previous exception would strip the stack trace that points
        // at the rule that actually blew up.
        $this->assertSame($inner, $e->getPrevious());
    }

    #[UnitTest]
    #[Test]
    public function problemParsingValidationRulesKeepsTheOriginalException(): void
    {
        $inner = new RuntimeException('boom');

        $e = ProblemParsingValidationRules::forParam('name', $inner);

        $this->assertInstanceOf(ScribeException::class, $e);
        $this->assertStringContainsString('name', $e->getMessage());
        $this->assertSame($inner, $e->getPrevious());
    }

    #[UnitTest]
    #[Test]
    public function couldntStartDatabaseTransactionNamesTheConnectionAndTheConfigKey(): void
    {
        $inner = new RuntimeException('connection refused');

        $e = CouldntStartDatabaseTransaction::forConnection('reporting', $inner);

        $this->assertInstanceOf(ScribeException::class, $e);
        $this->assertStringContainsString('reporting', $e->getMessage());
        $this->assertStringContainsString('databaseConnectionsToTransact', $e->getMessage());
        $this->assertSame($inner, $e->getPrevious());
    }

    #[UnitTest]
    #[Test]
    public function databaseTransactionsNotSupportedNamesTheDriverAndWarnsAboutPersistence(): void
    {
        $e = DatabaseTransactionsNotSupported::create('analytics', 'mongodb');

        $this->assertInstanceOf(ScribeException::class, $e);
        $this->assertStringContainsString('mongodb', $e->getMessage());
        $this->assertStringContainsString('analytics', $e->getMessage());
        // The user is being told their data will really change; that warning is
        // the point of the message.
        $this->assertStringContainsString('persisted', $e->getMessage());
    }

    #[UnitTest]
    #[Test]
    public function groupNotFoundNamesTheGroupAndTheTagThatReferencedIt(): void
    {
        $e = GroupNotFound::forTag('Users', 'groupName');

        $this->assertInstanceOf(ScribeException::class, $e);
        $this->assertStringContainsString('Users', $e->getMessage());
        $this->assertStringContainsString('groupName', $e->getMessage());
    }
}
