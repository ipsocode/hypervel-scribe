<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Extracting;

use Hypervel\Routing\Router;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\Extractor;
use Ipsocode\Scribe\Extracting\Strategies\GetFromInlineValidatorBase;
use Ipsocode\Scribe\Tests\DatabaseTestCase;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use Ipsocode\Scribe\Tools\Globals;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;
use Workbench\App\Http\Controllers\FacadeValidationController;
use Workbench\App\Http\Controllers\InlineValidationController;

/**
 * Validation written inline in a controller method, rather than in a form
 * request. Scribe reads these out of the method's parsed AST without running
 * them, so every spelling — the helper, the facade, the controller trait, rules
 * held in a variable — is a separate branch of the parser.
 */
class InlineValidationTest extends DatabaseTestCase
{
    /**
     * Kept out of `workbench/routes/api.php` so the documented API the writer
     * and generate-command tests assert against stays as it is.
     */
    protected function defineRoutes(Router $router): void
    {
        $router->post('api/inline/query', [InlineValidationController::class, 'queryRules'])
            ->name('inline.query');
        $router->post('api/inline/untyped', [InlineValidationController::class, 'untypedParameters'])
            ->name('inline.untyped');
        $router->post('api/inline/enums', [InlineValidationController::class, 'enumArgumentVariants'])
            ->name('inline.enums');
        $router->post('api/inline/closure', fn () => ['data' => []])
            ->name('inline.closure');
        $router->post('api/inline/bag', [InlineValidationController::class, 'intoAnErrorBag'])
            ->name('inline.bag');
        $router->post('api/inline/facade-bag', [FacadeValidationController::class, 'storeWithBag'])
            ->name('inline.facadeBag');
    }

    private function extract(string $routeName): ExtractedEndpointData
    {
        return (new Extractor(new DocumentationConfig(config('scribe'))))
            ->processRoute($this->workbenchRoute($routeName));
    }

    #[Test]
    public function rulesAssignedToAVariableAreFollowedBackToTheAssignment(): void
    {
        $params = $this->extract('inline.variable')->bodyParameters;

        $this->assertArrayHasKey('title', $params);
        $this->assertTrue($params['title']->required);
        $this->assertStringStartsWith('The post title.', $params['title']->description);
    }

    #[Test]
    public function rulesGivenAsArraysAreReadTheSameAsPipeDelimitedOnes(): void
    {
        $params = $this->extract('inline.array')->bodyParameters;

        $this->assertSame('integer', $params['per_page']->type);
        $this->assertTrue($params['per_page']->required);
    }

    #[Test]
    public function aCommentAboveARuleSuppliesItsDescriptionAndExample(): void
    {
        $params = $this->extract('inline.array')->bodyParameters;

        $this->assertStringStartsWith('How many to return.', $params['per_page']->description);
        $this->assertSame(25, $params['per_page']->example);
    }

    #[Test]
    public function aNoExampleCommentSuppressesTheGeneratedExample(): void
    {
        $params = $this->extract('inline.array')->bodyParameters;

        $this->assertNull($params['status']->example);
        $this->assertStringNotContainsString('No-example', $params['status']->description);
    }

    #[Test]
    public function anEnumRuleObjectIsReadOutOfTheAst(): void
    {
        // `new Enum(PostStatus::class)` — never evaluated, only parsed.
        $this->assertSame(['draft', 'published'], $this->extract('inline.array')->bodyParameters['status']->enumValues);
    }

    #[Test]
    public function aStaticEnumRuleCallIsReadOutOfTheAst(): void
    {
        // `Rule::enum(PostStatus::class)` — a different AST node entirely.
        $this->assertSame(['draft', 'published'], $this->extract('inline.array')->bodyParameters['direction']->enumValues);
    }

    #[Test]
    public function validationThroughTheRequestFacadeIsFound(): void
    {
        $params = $this->extract('inline.facade')->bodyParameters;

        $this->assertArrayHasKey('email', $params);
        $this->assertStringContainsString('@', (string) $params['email']->example);
    }

    #[Test]
    public function validationIntoANamedErrorBagIsFound(): void
    {
        // `validateWithBag()` takes the bag name first, so the rules the finder
        // wants are one argument further along.
        $this->assertArrayHasKey('nickname', $this->extract('inline.bag')->bodyParameters);
        $this->assertArrayHasKey('nickname', $this->extract('inline.facadeBag')->bodyParameters);
    }

    #[Test]
    public function validationThroughTheControllerHelperIsFound(): void
    {
        $this->assertArrayHasKey('slug', $this->extract('inline.controller')->bodyParameters);
    }

    #[Test]
    public function rulesThatCannotBeReadStaticallyAreSkipped(): void
    {
        $params = $this->extract('inline.unreadable')->bodyParameters;

        // A computed key cannot name a parameter...
        $this->assertArrayNotHasKey('dynamic', $params);
        // ...and a computed rule value contributes nothing, but the parameter
        // is still documented rather than the endpoint failing.
        $this->assertArrayHasKey('computed', $params);
        $this->assertArrayHasKey('nested', $params);
    }

    #[Test]
    public function aMethodWithNoValidatorYieldsNoBodyParameters(): void
    {
        $this->assertEmpty($this->extract('inline.none')->bodyParameters);
    }

    #[Test]
    public function aCommentAboveTheValidatorMovesItToTheQueryString(): void
    {
        $endpoint = $this->extract('inline.query');

        $this->assertSame('What to search for.', $endpoint->queryParameters['q']->description);
        // Claimed by the query side, so not documented as a body parameter too.
        $this->assertSame([], $endpoint->bodyParameters);
    }

    #[Test]
    public function parametersWithNoUsableTypeAreWalkedPast(): void
    {
        // An untyped parameter and a union-typed one: neither can name a form
        // request, and neither should stop the endpoint being documented.
        $endpoint = $this->extract('inline.untyped');

        $this->assertSame([], $endpoint->bodyParameters);
        $this->assertSame('api/inline/untyped', $endpoint->uri);
    }

    #[Test]
    public function anEnumNamedByAStringIsReadTheSameAsAClassConstant(): void
    {
        $this->assertSame(['draft', 'published'], $this->extract('inline.enums')->bodyParameters['quoted']->enumValues);
    }

    #[Test]
    public function anEnumTheParserCannotNameContributesNoValues(): void
    {
        $params = $this->extract('inline.enums')->bodyParameters;

        // A variable says nothing statically, and `self::class` resolves to
        // something that isn't an enum at all. Both leave a plain parameter.
        $this->assertEmpty($params['computed']->enumValues);
        $this->assertEmpty($params['wrong']->enumValues);
    }

    #[Test]
    public function aClosureRouteHasNoMethodToReadValidationFrom(): void
    {
        // There is no controller method, so there is no AST — and no crash.
        $endpoint = $this->extract('inline.closure');

        $this->assertSame([], $endpoint->bodyParameters);
        $this->assertSame([], $endpoint->queryParameters);
    }

    #[Test]
    public function theBaseStrategyClaimsAValidatorEitherWayItIsCommented(): void
    {
        // The body and query strategies split validators between them on a
        // `// Query parameters` comment. The class they both extend is usable
        // on its own, and takes whatever it finds.
        $strategy = new GetFromInlineValidatorBase(new DocumentationConfig(config('scribe')));

        $params = $strategy($this->extract('inline.query'));

        $this->assertArrayHasKey('q', $params);
    }

    #[Test]
    public function aParameterWithNoCommentIsNamedInVerboseOutput(): void
    {
        // The comment above a rule is the only place an inline validator can
        // carry a description or an example, so a run that found none says
        // which parameter to annotate.
        Globals::$shouldBeVerbose = true;
        ConsoleOutputUtils::bootstrapOutput($output = new BufferedOutput);

        (new GetFromInlineValidatorBase(new DocumentationConfig(config('scribe'))))
            ->getParametersFromValidationRules(['title' => 'string'], ['body' => ['description' => 'The body.']]);

        $this->assertStringContainsString(
            "No extra data found for parameter 'title' from your inline validator",
            $output->fetch(),
        );
    }
}
