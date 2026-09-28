<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Hypervel\Routing\Controller;
use Workbench\App\Http\Requests\AnnotatedRequest;
use Workbench\App\Http\Requests\NoRulesRequest;
use Workbench\App\Http\Requests\PartialDataRequest;
use Workbench\App\Http\Requests\RulesOnlyRequest;
use Workbench\App\Http\Requests\SearchPostsRequest;
use Workbench\App\Http\Requests\UndocumentedRequest;
use Workbench\App\Http\Requests\ValidatorPostRequest;

/**
 * One endpoint per shape of FormRequest.
 *
 * The body and query strategies share a base class and differ only in which
 * FormRequests they claim and which method they read the descriptions out of,
 * so each shape has to be walked through rather than assumed from the one
 * fully-populated request StorePostRequest already covers.
 *
 * Registered by FormRequestVariantsTest rather than in the Workbench route
 * file, so the documented API the writer tests assert against is unchanged.
 *
 * @group Form request variants
 */
class FormRequestVariantsController extends Controller
{
    /**
     * Take a request whose class has no docblock.
     */
    public function undocumented(UndocumentedRequest $request): array
    {
        return [];
    }

    /**
     * Search posts.
     */
    public function search(SearchPostsRequest $request): array
    {
        return [];
    }

    /**
     * Redeem a code.
     */
    public function redeem(ValidatorPostRequest $request): array
    {
        return [];
    }

    /**
     * Report something.
     */
    public function report(RulesOnlyRequest $request): array
    {
        return [];
    }

    /**
     * Half-described.
     */
    public function partial(PartialDataRequest $request): array
    {
        return [];
    }

    /**
     * Nothing to validate.
     */
    public function open(NoRulesRequest $request): array
    {
        return [];
    }

    /**
     * Parameters documented on the request rather than here.
     *
     * The tag below is deliberately a different parameter: the request's tags
     * take the whole endpoint, so this one should not be documented at all.
     *
     * @queryParam limit integer How many to return. Example: 10
     */
    public function annotated(AnnotatedRequest $request): array
    {
        return [];
    }
}
