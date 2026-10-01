<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Hypervel\Http\Request;
use Hypervel\Routing\Controller;
use Hypervel\Support\Facades\Validator;
use Ipsocode\Scribe\Attributes\BodyParam;
use Ipsocode\Scribe\Attributes\Deprecated;
use Ipsocode\Scribe\Attributes\Endpoint;
use Ipsocode\Scribe\Attributes\Group;
use Ipsocode\Scribe\Attributes\Header;
use Ipsocode\Scribe\Attributes\Response;
use Ipsocode\Scribe\Attributes\ResponseField;
use Ipsocode\Scribe\Attributes\ResponseFromApiResource;
use Ipsocode\Scribe\Attributes\ResponseFromFile;
use Ipsocode\Scribe\Attributes\Subgroup;
use Ipsocode\Scribe\Attributes\Unauthenticated;
use Workbench\App\Http\Resources\PostResource;
use Workbench\App\Models\Post;

/**
 * The rest of the annotation surface, which the Post and User controllers do
 * not reach: inline validators, the remaining docblock tags, and the response
 * attributes.
 *
 * Split out rather than piled onto PostController because inline validation is
 * read out of the method's own AST — so each style of inline validator needs a
 * method of its own to be exercised at all.
 *
 * @group Comments
 *
 * Endpoints for post comments.
 */
class CommentController extends Controller
{
    /**
     * List comments.
     *
     * @queryParam post_id integer required The post to list comments for. Example: 3
     * @queryParam per_page integer Comments per page. Example: 15
     *
     * @header X-Trace-Id 0c4c6bfa
     *
     * @responseField id integer The comment id.
     * @responseField body string The comment body.
     *
     * @response {"data": [{"id": 1, "body": "Nice post."}]}
     */
    public function index(): array
    {
        return ['data' => []];
    }

    /**
     * Show a comment.
     *
     * @urlParam id integer required The comment id. Example: 1
     *
     * @responseFile responses/comment.json
     *
     * @responseFile status=404 scenario="comment not found" responses/comment.json {"data": null}
     */
    public function show(int $id): array
    {
        return [];
    }

    /**
     * Create a comment.
     *
     * The body parameters are read out of the inline `$request->validate()`
     * call below, and the descriptions out of the `@bodyParam` tags.
     *
     * @bodyParam body string required The comment body. Example: Nice post.
     * @bodyParam notify boolean Whether to notify the author. Example: true
     */
    public function store(Request $request): array
    {
        return $request->validate([
            'body' => 'required|string|max:500',
            'notify' => 'boolean',
        ]);
    }

    /**
     * Report a comment.
     *
     * Uses the Validator facade rather than the request helper — a separate
     * code path in the inline-validator strategy.
     */
    public function report(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            // The reason the comment is being reported.
            'reason' => 'required|string|in:spam,abuse',
        ]);

        return $validator->validated();
    }

    #[Endpoint('Pin a comment', 'Pins a comment to the top of the thread.')]
    #[Subgroup('Moderation', 'Endpoints for moderators.')]
    #[Header('X-Trace-Id', '0c4c6bfa')]
    #[BodyParam('until', 'string', 'When the pin expires.', required: false, example: '2030-01-01')]
    #[Response(['pinned' => true], 200, 'Pinned')]
    #[Response(status: 409, description: 'Already pinned')]
    #[ResponseField('pinned', 'boolean', 'Whether the comment is now pinned.')]
    #[Unauthenticated]
    public function pin(): array
    {
        return ['pinned' => true];
    }

    #[Endpoint('Export a comment')]
    #[Subgroup('Moderation')]
    #[ResponseFromFile('responses/comment.json', 200, ['meta' => ['exported' => true]], 'The exported comment')]
    public function export(): array
    {
        return [];
    }

    #[Endpoint('Unpin a comment')]
    #[Subgroup('Moderation')]
    #[Deprecated]
    #[ResponseFromApiResource(PostResource::class, Post::class, status: 200, description: 'The post', factoryStates: ['published'])]
    public function unpin(): array
    {
        return ['pinned' => false];
    }
}
