<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Request;
use Hypervel\Routing\Controller;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Workbench\App\Models\Post;

/**
 * Endpoints that exist to be *called* rather than to be documented.
 *
 * Every other Workbench controller returns a fixed payload, which is all the
 * annotation-driven response strategies need. `ResponseCalls` is the one
 * strategy that dispatches a real request, so testing it needs endpoints that
 * report what actually arrived — the query string, the body, the cookies, the
 * files, the config in force at the time.
 *
 * These are deliberately **not** registered in `workbench/routes/api.php`:
 * `ResponseCallsTest` registers them itself through `defineRoutes()`, so the
 * documented Workbench API — what the matcher, the writers and the generate
 * command all assert against — stays exactly as it was.
 *
 * @group Response calls
 */
class ResponseCallController extends Controller
{
    /**
     * Echo the call back.
     *
     * @queryParam filter string Which posts to return. Example: published
     */
    public function echo(Request $request): array
    {
        return [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'query' => $request->query->all(),
            'body' => $request->request->all(),
            'cookies' => $request->cookies->all(),
            'files' => array_keys($request->allFiles()),
            'authorization' => $request->headers->get('Authorization'),
            'apiKeyHeader' => $request->headers->get('Api-Key'),
            'appName' => config('app.name'),
        ];
    }

    /**
     * Echo a bound URL parameter back.
     *
     * @urlParam id integer required The post id. Example: 7
     */
    public function show(string $id): array
    {
        // Route parameters arrive as strings; the cast is what makes "did the
        // extracted example value reach the path" readable at the assertion.
        return ['id' => (int) $id];
    }

    /**
     * Write a row, so the caller can check whether it survived.
     */
    public function persist(): array
    {
        Post::query()->create(['title' => 'Written by a response call', 'body' => 'Body.']);

        return ['count' => Post::query()->count()];
    }

    /**
     * Answer in chunks rather than in one body.
     */
    public function stream(): StreamedResponse
    {
        return response()->stream(function (): void {
            echo 'first-chunk;';
            ob_flush();
            echo 'second-chunk';
        });
    }

    /**
     * Take longer to answer than a caller in a hurry will wait.
     */
    public function slow(): array
    {
        Coroutine::sleep(1.0);

        return ['slept' => true];
    }

    /**
     * Blow up.
     */
    public function failing(): never
    {
        throw new RuntimeException('The endpoint blew up.');
    }
}
