<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Extracting;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Routing\Router;
use Hypervel\Support\Facades\DB;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\Extractor;
use Ipsocode\Scribe\Extracting\Strategies\UrlParameters\GetFromLaravelAPI;
use Ipsocode\Scribe\Scribe;
use Ipsocode\Scribe\Tests\DatabaseTestCase;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use Ipsocode\Scribe\Tools\RunState;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Http\Controllers\UrlParamsController;

/**
 * URL parameters are documented from the URI and the controller method's type
 * hints together: the URI says what the parameters are called, and the type
 * hints say what they are. Neither alone is enough, so every combination of the
 * two is a branch — and the description is guessed from the URL's own wording.
 *
 * The routes live here rather than in `workbench/routes/api.php` so the
 * documented API the writer and generate-command tests assert against stays as
 * it is.
 */
class UrlParametersTest extends DatabaseTestCase
{
    protected function defineRoutes(Router $router): void
    {
        $router->get('api/params/categories/{category}', [UrlParamsController::class, 'namedAfterItsThing'])
            ->name('params.category');
        $router->get('api/params/blogs/{post}', [UrlParamsController::class, 'boundModel'])
            ->name('params.bound');
        $router->get('api/params/status/{status}', [UrlParamsController::class, 'backedEnum'])
            ->name('params.backedEnum');
        $router->get('api/params/direction/{direction}', [UrlParamsController::class, 'pureEnum'])
            ->name('params.pureEnum');
        $router->get('api/params/things/{thing?}', [UrlParamsController::class, 'optionalParam'])
            ->name('params.optional');
        $router->get('api/params/pallets/{id?}', [UrlParamsController::class, 'optionalParam'])
            ->name('params.optionalId');
        $router->get('api/params/tickets/{ticket}-{revision}', [UrlParamsController::class, 'twoParamsInOneSegment'])
            ->name('params.sharedSegment');
        $router->get('{widget}', [UrlParamsController::class, 'wholeUrlIsTheParam'])
            ->name('params.whole');
        $router->get('api/params/gadgets/{id}', [UrlParamsController::class, 'unreflectableArguments'])
            ->name('params.unreflectable');
        $router->get('api/params/codes/{code}', [UrlParamsController::class, 'namedAfterItsThing'])
            ->where('code', '[A-Z]{3}')
            ->name('params.constrained');
        $router->get('api/params/widgets/{id}', [UrlParamsController::class, 'namedAfterItsThing'])
            ->name('params.widget');
        $router->get('api/params/gizmos/{id}', [UrlParamsController::class, 'namedAfterItsThing'])
            ->name('params.gizmo');
        $router->get('api/params/slugs/{post:slug}', [UrlParamsController::class, 'boundModel'])
            ->name('params.inlineBound');
    }

    private function extract(string $routeName): ExtractedEndpointData
    {
        return (new Extractor(new DocumentationConfig(config('scribe'))))
            ->processRoute($this->workbenchRoute($routeName));
    }

    #[Test]
    public function describesAParameterNamedAfterTheThingBeforeIt(): void
    {
        $this->assertSame(
            'The category.',
            $this->extract('params.category')->urlParameters['category']->description,
        );
    }

    #[Test]
    public function aBoundModelSuppliesTheTypeAndAnExampleFromTheDatabase(): void
    {
        $post = \Workbench\App\Models\Post::factory()->create();

        $param = $this->extract('params.bound')->urlParameters['post_id'];

        $this->assertSame('integer', $param->type);
        $this->assertSame($post->id, $param->example);
    }

    #[Test]
    public function aModelsExampleRowIsReadOncePerRun(): void
    {
        $post = \Workbench\App\Models\Post::factory()->create();
        DB::enableQueryLog();

        $firstRowReads = fn (): int => collect(DB::getQueryLog())
            ->where('query', 'select * from "posts" limit 1')
            ->count();

        // Two routes bound to the same model share the one lookup.
        $this->assertSame($post->id, $this->extract('params.bound')->urlParameters['post_id']->example);
        $this->assertSame($post->id, $this->extract('blogs.show')->urlParameters['id']->example);
        $this->assertSame($post->id, $this->extract('params.bound')->urlParameters['post_id']->example);
        $this->assertSame(1, $firstRowReads());

        // The next run reads it afresh.
        RunState::flush();
        $this->extract('params.bound');
        $this->assertSame(2, $firstRowReads());
    }

    #[Test]
    public function aBoundModelWithNoRowsLeavesTheExampleToBeGenerated(): void
    {
        // `Model::first()` returns null on an empty table, so the example has
        // to fall through to a generated one rather than a null dereference.
        $this->assertIsInt($this->extract('params.bound')->urlParameters['post_id']->example);
    }

    #[Test]
    public function aParameterKeepsItsHypervelNameWhenTheNormalizerIsReplaced(): void
    {
        // Without the rewrite to `{post_id}`, the bound model has to be matched
        // to the parameter by the argument's own name.
        Scribe::normalizeEndpointUrlUsing(fn (string $url) => $url);

        $param = $this->extract('params.bound')->urlParameters['post'];

        $this->assertSame('integer', $param->type);
    }

    #[Test]
    public function aBackedEnumSuppliesTheTypeAndTheFirstCaseAsTheExample(): void
    {
        $param = $this->extract('params.backedEnum')->urlParameters['status'];

        $this->assertSame('string', $param->type);
        $this->assertSame('draft', $param->example);
    }

    #[Test]
    public function aPureEnumFallsBackToAStringWithAGeneratedExample(): void
    {
        // No backing type and no backing value: both lookups have to fail
        // softly rather than take the endpoint down.
        $param = $this->extract('params.pureEnum')->urlParameters['direction'];

        $this->assertSame('string', $param->type);
        $this->assertNotNull($param->example);
    }

    #[Test]
    public function anOptionalParameterIsNotRequiredButIsStillNamedAfterItsThing(): void
    {
        // The URI spells it `{thing?}`, but the `?` belongs to the URI rather
        // than to the parameter, so the segment before it names it either way.
        $param = $this->extract('params.optional')->urlParameters['thing'];

        $this->assertFalse($param->required);
        $this->assertSame('The thing.', $param->description);
    }

    #[Test]
    public function anOptionalIdIsNamedAfterTheSegmentBeforeIt(): void
    {
        // Searching the raw URI for `{id}` never matched `{id?}`, which left
        // the sentence with a dangling article: "The ID of the ."
        $this->assertSame(
            'The ID of the pallet.',
            $this->extract('params.optionalId')->urlParameters['id']->description,
        );
    }

    #[Test]
    public function parametersSharingASegmentHaveNoSegmentOfTheirOwnToBeNamedAfter(): void
    {
        // The search is for a segment that is the parameter and nothing else,
        // so neither of these is found in the URL at all.
        $parameters = $this->extract('params.sharedSegment')->urlParameters;

        $this->assertSame('', $parameters['ticket']->description);
        $this->assertSame('', $parameters['revision']->description);
    }

    #[Test]
    public function aUrlThatIsNothingButItsParameterHasNoThingToNameItAfter(): void
    {
        $this->assertSame('', $this->extract('params.whole')->urlParameters['widget']->description);
    }

    #[Test]
    public function aWhereConstraintShapesTheGeneratedExample(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[A-Z]{3}$/',
            $this->extract('params.constrained')->urlParameters['code']->example,
        );
    }

    #[Test]
    public function argumentsTheContainerCannotBuildAreWalkedPast(): void
    {
        // One needs a constructor argument, one names an unbound interface, and
        // one is an interface the container does resolve — none of the three is
        // a model, so none of them says anything about `{id}`.
        $this->assertSame('string', $this->extract('params.unreflectable')->urlParameters['id']->type);
    }

    #[Test]
    public function aModelIsFoundFromTheUrlEvenWithoutModelBinding(): void
    {
        // `/widgets/{id}` with an `App\Models\Widget` in the application: the
        // model names the parameter's type even though the method never
        // mentions it.
        $param = $this->extract('params.widget')->urlParameters['id'];

        // The model's key type, and — because the model has no table to read a
        // row from — a generated example rather than a failed lookup.
        $this->assertSame('integer', $param->type);
        $this->assertIsInt($param->example);
    }

    #[Test]
    public function anInlineBindingNamesTheParameterAfterTheBoundField(): void
    {
        // `/slugs/{post:slug}` says which column the model is looked up by, and
        // that is what the parameter should be called.
        $this->assertSame('api/params/slugs/{post_slug}', $this->extract('params.inlineBound')->uri);
    }

    #[Test]
    public function aParameterThatAlreadyHasATypeIsLeftAlone(): void
    {
        // A strategy extending this one can type a parameter itself — from an
        // `@urlParam` tag, say — and the model-from-the-URL guess must not
        // overwrite it.
        $strategy = new class(new DocumentationConfig(config('scribe'))) extends GetFromLaravelAPI {
            public function inferFrom(array $parameters, ExtractedEndpointData $endpointData): array
            {
                return $this->inferBetterTypesAndExamplesForEloquentUrlParameters($parameters, $endpointData);
            }
        };

        $inferred = $strategy->inferFrom(
            ['id' => ['name' => 'id', 'type' => 'string', 'required' => true]],
            $this->extract('params.widget'),
        );

        $this->assertSame('string', $inferred['id']['type']);
    }

    #[Test]
    public function aUrlThingThatNamesSomethingUninstantiableIsIgnored(): void
    {
        // `App\Models\Gizmo` is an enum, so `new` on it raises an Error rather
        // than producing a model.
        $this->assertSame('string', $this->extract('params.gizmo')->urlParameters['id']->type);
    }
}

/**
 * A model in the application's own namespace, which is where Scribe looks when
 * a URL names a thing but the controller does not type-hint it. It has no
 * table, so reading an example row off it fails — which is the branch that has
 * to fall back to a generated example.
 */
class Widget extends Model
{
    protected ?string $table = 'widgets';
}

/**
 * Named the same way a model would be, but not a model — and not something
 * `new` will even accept.
 */
enum Gizmo: string
{
    case One = 'one';
}

class_alias(Widget::class, 'App\Models\Widget');
class_alias(Gizmo::class, 'App\Models\Gizmo');
