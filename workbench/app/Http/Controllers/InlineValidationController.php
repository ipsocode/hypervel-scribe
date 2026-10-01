<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Hypervel\Foundation\Validation\ValidatesRequests;
use Hypervel\Http\Request;
use Hypervel\Routing\Controller;
use Hypervel\Validation\Rule;
use Hypervel\Validation\Rules\Enum;
use Workbench\App\Enums\PostStatus;

/**
 * Every spelling of an inline validator.
 *
 * The strategy reads these out of the method's own AST rather than by running
 * them, so each spelling is a distinct branch and needs a method of its own —
 * a single method with one validator would leave most of the parser untested.
 *
 * Note the shape: the finders match a validation *statement*, so the result is
 * assigned and then used rather than returned directly. An application that
 * writes `return $request->validate([...])` is not documented at all — a wart
 * worth knowing about rather than one to hide behind a fixture.
 *
 * The assignment has to be genuinely used, too: php-cs-fixer's
 * `return_assignment` rule rewrites `$x = ...; return $x;` into `return ...;`,
 * which silently turns a documented endpoint into an undocumented one.
 *
 * @group Inline validation
 */
class InlineValidationController extends Controller
{
    use ValidatesRequests;

    /**
     * Rules held in a variable.
     *
     * The strategy walks back through earlier statements to find the
     * assignment, because `$request->validate($rules)` says nothing on its own.
     */
    public function fromVariable(Request $request): array
    {
        $rules = [
            // The post title.
            'title' => 'required|string',
        ];

        $validated = $request->validate($rules);

        return ['data' => $validated];
    }

    /**
     * Rules as arrays rather than pipe-delimited strings.
     */
    public function arrayRules(Request $request): array
    {
        $validated = $request->validate([
            // How many to return. Example: 25
            'per_page' => ['required', 'integer'],
            // The status. No-example
            'status' => ['nullable', new Enum(PostStatus::class)],
            // Sort direction.
            'direction' => ['nullable', Rule::enum(PostStatus::class)],
        ]);

        return ['data' => $validated];
    }

    /**
     * Validation through the controller's own validate() helper.
     */
    public function viaController(Request $request): array
    {
        $validated = $this->validate($request, [
            'slug' => 'required|string',
        ]);

        return ['data' => $validated];
    }

    /**
     * Rules the parser cannot read statically.
     *
     * A computed key and a computed value are both skipped rather than guessed
     * at, and must not take the endpoint down with them.
     */
    public function unreadableRules(Request $request): array
    {
        $key = 'dynamic';

        $validated = $request->validate([
            $key => 'required',
            'computed' => str_repeat('a', 3),
            'nested' => ['required', 5],
        ]);

        return ['data' => $validated];
    }

    /**
     * Rules a comment claims for the query string.
     *
     * The same `$request->validate()` call feeds two strategies; a
     * `// Query parameters` comment above it is what decides which one takes
     * it.
     */
    public function queryRules(Request $request): array
    {
        // Query parameters
        $validated = $request->validate([
            // What to search for.
            'q' => 'required|string',
        ]);

        return ['data' => $validated];
    }

    /**
     * A method whose parameters name nothing worth reading.
     *
     * Scribe looks through a method's parameters for a form request; one with
     * no type at all and one with a union type are both things it has to walk
     * past rather than reflect on.
     *
     * @param null|mixed $note
     */
    public function untypedParameters($note = null, Request|string $extra = ''): array
    {
        return [];
    }

    /**
     * Every other way an enum can be named in an enum rule.
     *
     * The AST is all Scribe has, so each spelling of the argument is a
     * separate branch: a class constant, a plain string, and something it
     * cannot read at all.
     */
    public function enumArgumentVariants(Request $request): array
    {
        $chosen = PostStatus::class;

        $validated = $request->validate([
            // Named as a string rather than a class constant.
            'quoted' => ['nullable', Rule::enum('Workbench\App\Enums\PostStatus')],
            // Named by a variable, which says nothing statically.
            'computed' => ['nullable', Rule::enum($chosen)],
            // Names this controller, not an enum — a mistake to walk past.
            'wrong' => ['nullable', Rule::enum(self::class)],
        ]);

        return ['data' => $validated];
    }

    /**
     * Validation into a named error bag.
     *
     * The rules are the second argument rather than the first, so the finder
     * has to read a different position.
     */
    public function intoAnErrorBag(Request $request): array
    {
        $validated = $request->validateWithBag('signup', [
            'nickname' => 'required|string',
        ]);

        return ['data' => $validated];
    }

    /**
     * A method with no validator at all.
     */
    public function noValidation(): array
    {
        return [];
    }
}
