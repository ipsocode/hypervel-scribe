<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Hypervel\Routing\Controller;
use Ipsocode\Scribe\Attributes\Response;
use Ipsocode\Scribe\Attributes\ResponseField;
use Workbench\App\Http\Controllers\Concerns\StoresPosts;

/**
 * Endpoints that used to abort generation with a TypeError.
 *
 * Kept off `workbench/routes/api.php`: `CrashingEndpointsTest` registers them
 * itself, so the documented Workbench API stays as it was.
 *
 * @group Crashing
 */
class CrashingEndpointsController extends Controller
{
    use StoresPosts {
        store as storeAliased;
    }

    /**
     * @apiResourceCollection \Workbench\App\Http\Resources\PostResource
     *
     * @apiResourceModel \Workbench\App\Models\Post paginate=15
     */
    public function paginated(): array
    {
        return [];
    }

    /**
     * @apiResourceCollection \Workbench\App\Http\Resources\PostResource
     *
     * @apiResourceModel \Workbench\App\Models\Post paginate=15,simple
     */
    public function simplePaginated(): array
    {
        return [];
    }

    /**
     * @apiResourceCollection \Workbench\App\Http\Resources\PostResource
     *
     * @apiResourceModel \Workbench\App\Models\Post paginate=15,cursor
     */
    public function cursorPaginated(): array
    {
        return [];
    }

    #[Response(status: 204)]
    #[ResponseField('id')]
    public function noContent(): array
    {
        return [];
    }
}
