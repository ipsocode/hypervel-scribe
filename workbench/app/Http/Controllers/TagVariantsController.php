<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Hypervel\Routing\Controller;

/**
 * The many spellings of a parameter tag.
 *
 * `@queryParam` and friends take name, then an optional type, then optional
 * `required` / `deprecated` markers, then a description — every part optional,
 * every combination a separate branch of the parser. One endpoint per shape.
 *
 * @group Tag variants
 */
class TagVariantsController extends Controller
{
    /**
     * Name only.
     *
     * @queryParam bare
     */
    public function bare(): array
    {
        return [];
    }

    /**
     * Name and description, no type.
     *
     * @queryParam text The text to search for.
     * @queryParam page The page to fetch.
     * @queryParam item_count The number of items.
     */
    public function described(): array
    {
        return [];
    }

    /**
     * Name and type only.
     *
     * @queryParam user_id integer
     */
    public function typedOnly(): array
    {
        return [];
    }

    /**
     * Required and deprecated markers, with and without descriptions.
     *
     * @queryParam a required
     * @queryParam b deprecated
     * @queryParam c string required The c.
     * @queryParam d string deprecated Use `c` instead.
     * @queryParam e required The e.
     * @queryParam f deprecated The f.
     */
    public function markers(): array
    {
        return [];
    }

    /**
     * A word that looks like a type but is not one.
     *
     * @queryParam colour Blue or green.
     */
    public function notAType(): array
    {
        return [];
    }

    /**
     * No-example suppresses the generated example.
     *
     * @queryParam token string The token. No-example
     */
    public function noExample(): array
    {
        return [];
    }

    /**
     * The same spellings again, for `@urlParam`.
     *
     * A separate parser from `@queryParam`'s, with its own idea of which types
     * a URL segment can hold — so the shapes have to be walked through twice.
     *
     * @urlParam bare
     * @urlParam marked required
     * @urlParam typed integer
     * @urlParam page The page number.
     */
    public function urlParamVariants(): array
    {
        return [];
    }

    /**
     * And again for `@bodyParam`, which additionally takes `deprecated`.
     *
     * @bodyParam bare string
     * @bodyParam marked string required
     * @bodyParam legacy string deprecated
     */
    public function bodyParamVariants(): array
    {
        return [];
    }

    /**
     * A file body parameter, with and without an example.
     *
     * A file example is a path, and Scribe turns it into a real uploaded file
     * so a response call has something to send. One file anywhere in the body
     * also makes the whole endpoint multipart.
     *
     * @bodyParam avatar file required The avatar to upload. Example: workbench/storage/responses/comment.json
     * @bodyParam caption string The caption. Example: Me, yesterday
     */
    public function fileUpload(): array
    {
        return [];
    }

    /**
     * Response fields whose types are inferred from the response body.
     *
     * @responseField id The id.
     * @responseField name The name.
     * @responseField nested The nested one.
     *
     * @response {"data": {"id": 1, "name": "Ada", "nested": {"a": 1}}}
     */
    public function inferredResponseFields(): array
    {
        return [];
    }

    /**
     * A response field naming something the response body does not contain.
     *
     * @responseField ghost The one that got away.
     *
     * @response {"id": 1}
     */
    public function unknownResponseField(): array
    {
        return [];
    }

    /**
     * Response fields inferred from a list response.
     *
     * @responseField id The id.
     *
     * @response [{"id": 1}]
     */
    public function listResponseFields(): array
    {
        return [];
    }
}
