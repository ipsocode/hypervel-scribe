<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Hypervel\Routing\Controller;
use Ipsocode\Scribe\Attributes\Authenticated;
use Ipsocode\Scribe\Attributes\Endpoint;
use Ipsocode\Scribe\Attributes\Group;
use Ipsocode\Scribe\Attributes\QueryParam;
use Ipsocode\Scribe\Attributes\ResponseFromApiResource;
use Ipsocode\Scribe\Attributes\UrlParam;
use Workbench\App\Http\Resources\UserResource;
use Workbench\App\Models\User;

/**
 * The PHP-attribute half of the Workbench API.
 *
 * Deliberately carries no Scribe docblock tags: an endpoint documented purely
 * through attributes is the case where a regression in the attribute
 * strategies cannot be masked by the docblock ones picking up the slack.
 */
#[Group('Users', 'Endpoints for reading users.')]
class UserController extends Controller
{
    #[Endpoint('List users', 'Returns every user.')]
    #[QueryParam('per_page', 'integer', 'Users per page.', required: false, example: 15)]
    // No example given, so Scribe generates one.
    #[QueryParam('sort', 'string', 'The field to sort by.')]
    // And the opt-out: an example is generated for every parameter unless the
    // endpoint says not to.
    #[QueryParam('cursor', 'string', 'An opaque cursor.', example: 'No-example')]
    #[ResponseFromApiResource(UserResource::class, User::class, collection: true)]
    public function index(): mixed
    {
        return UserResource::collection(User::query()->get());
    }

    #[Endpoint('Show a user')]
    #[UrlParam('id', 'integer', 'The user id.', example: 1)]
    #[ResponseFromApiResource(UserResource::class, User::class)]
    public function show(int $id): mixed
    {
        return new UserResource(User::query()->findOrFail($id));
    }

    #[Endpoint('Show the current user')]
    #[Authenticated]
    #[ResponseFromApiResource(UserResource::class, User::class)]
    public function me(): mixed
    {
        return new UserResource(User::query()->firstOrFail());
    }
}
