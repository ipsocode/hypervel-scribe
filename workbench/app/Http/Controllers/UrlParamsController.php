<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Routing\Controller;
use Workbench\App\Contracts\Unbound;
use Workbench\App\Enums\Direction;
use Workbench\App\Enums\PostStatus;
use Workbench\App\Models\Post;
use Workbench\App\Support\NeedsArguments;

/**
 * Controller methods whose *arguments* are the fixture.
 *
 * URL parameters are documented from two places at once — the URI, and the
 * type hints on the method the route points at — so each kind of type hint is a
 * separate branch: a bound model, a backed enum, a pure enum, an injected
 * dependency Scribe cannot construct, and an interface it can only ask the
 * container for.
 *
 * @group URL parameters
 */
class UrlParamsController extends Controller
{
    /**
     * A parameter named after the thing that precedes it in the URL.
     */
    public function namedAfterItsThing(string $category): array
    {
        return [];
    }

    /**
     * A model-bound parameter.
     */
    public function boundModel(Post $post): array
    {
        return [];
    }

    /**
     * A backed enum, whose cases supply both the type and the example.
     */
    public function backedEnum(PostStatus $status): array
    {
        return [];
    }

    /**
     * A pure enum, which supplies neither.
     */
    public function pureEnum(Direction $direction): array
    {
        return [];
    }

    /**
     * An optional parameter.
     */
    public function optionalParam(?string $thing = null): array
    {
        return [];
    }

    /**
     * Two parameters sharing a single URL segment.
     */
    public function twoParamsInOneSegment(string $ticket, string $revision): array
    {
        return [];
    }

    /**
     * A URL that is nothing but its parameter.
     */
    public function wholeUrlIsTheParam(string $widget): array
    {
        return [];
    }

    /**
     * Arguments that are neither models nor enums.
     *
     * `$formatter` cannot be constructed without its own argument, and
     * `$unbound` names an interface nothing has bound — both are walked past.
     * `$config` is an interface the container does resolve, which is the branch
     * that has to return the resolved instance rather than null.
     */
    public function unreflectableArguments(
        NeedsArguments $formatter,
        Unbound $unbound,
        Repository $config,
        string $id,
    ): array {
        return [];
    }
}
