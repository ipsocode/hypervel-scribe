# Documenting endpoints

Each documented route is read by a list of [strategies](strategies.md). With the
default lists, an endpoint is described by:

- the route itself: its URI, methods and the controller method's type hints;
- the controller's and the method's docblocks;
- PHP attributes from [`src/Attributes`](../src/Attributes);
- the validation rules of a FormRequest or an inline validator;
- a call to the endpoint (a response call, `GET` routes only in the published
  config).

Many of the examples below are adapted from the Workbench controllers under
[`workbench/app/Http/Controllers`](../workbench/app/Http/Controllers).

## Title, description and group

The docblock's first line is the endpoint's title and the rest of its text is
the description. `@group` names the group; lines after it describe the group.
On the controller's docblock, `@group` applies to every method that has none.

```php
/**
 * @group Posts
 *
 * Endpoints for reading and writing blog posts.
 */
class PostController extends Controller
{
    /**
     * List posts.
     *
     * Returns a page of posts, newest first.
     */
    public function index(Request $request): mixed
```

When a method's docblock starts with `@group`, the line after the group name is
the title rather than the group's description.

`@subgroup` and `@subgroupDescription` sort endpoints within a group, on the
method or the controller:

```php
/**
 * Sorted into a subgroup by the method.
 *
 * @subgroup Method subgroup
 *
 * @subgroupDescription Written on the method.
 */
```

Endpoints without a group go into `groups.default` (`Endpoints`).

## Authentication, deprecation and hiding

| Tag | Effect |
|---|---|
| `@authenticated` | Marks the endpoint as needing authentication. |
| `@unauthenticated` | Marks it as public, for APIs where `auth.default` is `true`. |
| `@deprecated` | Marks it deprecated. Text after the tag is shown as the reason: `@deprecated use groupWithTitle instead`. |
| `@hideFromAPIDocumentation` | Leaves the endpoint out. On the controller, leaves out every method. |

The method's tag wins over the controller's. An authenticated endpoint gets a
"requires authentication" badge; the credential is added to its example requests
and response calls only when `auth.enabled` is `true`. See
[configuration](configuration.md#authentication).

## Parameters

```text
@urlParam   <name> [type] [required] <description>
@queryParam <name> [type] [required] [deprecated] <description>
@bodyParam  <name> <type> [required] [deprecated] <description>
```

```php
/**
 * @urlParam id integer required The post id. Example: 3
 * @queryParam page integer The page to fetch. Example: 2
 * @queryParam status string Filter by status. Enum: draft, published Example: published
 * @queryParam token string The token. No-example
 * @bodyParam body string required The comment body. Example: Nice post.
 * @bodyParam avatar file required The avatar to upload. Example: storage/app/avatar.png
 */
```

- Types are `string`, `integer` (`int`), `number` (`float`, `double`),
  `boolean` (`bool`) and `object`, with `[]` for a list (`string[]`,
  `object[]`). `@bodyParam` also takes `file`, which makes the request
  multipart. `@urlParam` takes `string`, `integer` and `number` only.
- Without a type, `@queryParam` and `@urlParam` use `string`, or `integer` when
  the description mentions a number, count or page.
- `Example: <value>` sets the example, cast to the type. `Example: null` sets a
  null example. Without one, an example is generated. `No-example` in the
  description stops that.
- `Enum: a, b, c` lists the allowed values. Put it before `Example:`.
- Fields of an object or a list of objects use dots and `[]`:
  `@bodyParam author.name string` and `@bodyParam tags[].id integer`.

Tags on the controller's docblock apply to every method, and a method's tag of
the same name replaces them. When the method's FormRequest has parameter tags in
its own docblock, those are used and the method's are ignored.

URL parameters are also read from the route and the method's type hints. A
parameter bound to an Eloquent model takes its type and an example from the
model, and a backed enum supplies its type and its first case's value as the
example. Parameter names
are rewritten to say what they hold: on a resource route (named `*.index`,
`*.show`, `*.store`, `*.update` or `*.destroy`), `authors/{author}/blogs/{blog}`
becomes `authors/{author_id}/blogs/{id}`; elsewhere a parameter bound to a
type-hinted model, or written `{post:slug}`, becomes `{post_id}` or
`{post_slug}`. [`Scribe::normalizeEndpointUrlUsing()`](hooks.md#normalizeendpointurlusing)
replaces this.

## Headers

```php
/**
 * @header X-Trace-Id 0c4c6bfa
 */
```

`@header <name> [example]`. Without an example, one is generated.

## Responses

| Tag | Response |
|---|---|
| `@response [status] <content>` | The content as given. |
| `@responseFile [status] <path> [json]` | The file's content, with the JSON object, if given, merged over it. A relative path is tried from the working directory, then from `storage/`. |
| `@apiResource <class>` | An API resource rendered from an example model. |
| `@apiResourceCollection <class>` | The same, as a collection. |
| `@transformer <class>` | A Fractal transformer's output for an example model. |
| `@transformerCollection <class>` | The same, as a collection. |

`@response`, `@responseFile` and the `@apiResource` tags also take `status=`
and `scenario="..."` in place of a leading status; the scenario becomes the
response's description:

```php
/**
 * @response {"data": [{"id": 1, "body": "Nice post."}]}
 * @response status=201 {"id": 1, "title": "My first post", "published": false}
 * @responseFile responses/comment.json
 * @responseFile status=404 scenario="comment not found" responses/comment.json {"data": null}
 */
```

### API resources

```php
/**
 * @apiResourceCollection \Workbench\App\Http\Resources\PostResource
 *
 * @apiResourceModel \Workbench\App\Models\Post states=published with=author paginate=15
 *
 * @apiResourceAdditional meta="from the docs"
 */
```

- `@apiResourceModel` names the model. Without it, the model is read from an
  `@mixin` tag on the resource class.
- `states=` lists factory states, `with=` the relations to load (nested with
  dots), and `paginate=` the page size: `paginate=15`, `paginate=15,simple` or
  `paginate=15,cursor`. The three can go on `@apiResource` instead, but only
  when there is no `@apiResourceModel`: with one, its own fields are used.
- `@apiResourceAdditional` lists `key=value` pairs passed to the resource's
  `additional()`.

How the example model is made is set by `examples.models_source`; see
[example models](hooks.md#example-models).

### Transformers

```php
/**
 * @transformerCollection 201 \Workbench\App\Transformers\PostTransformer
 *
 * @transformerModel \Workbench\App\Models\Post states=published resourceKey=posts
 *
 * @transformerPaginator \Workbench\App\Transformers\HypervelPaginatorAdapter 15
 */
```

`@transformerModel` takes `states=`, `with=` and `resourceKey=`. Without it, the
model is the type of the transformer's `transform()` parameter.
`@transformerPaginator` names the paginator adapter and the page size.
`fractal.serializer` sets the serializer.

### Response fields

```php
/**
 * @responseField id integer The comment id.
 * @responseField body string The comment body.
 */
```

`@responseField <name> [type] [required] <description>`. Without a type, it is
read from the endpoint's response.

Fields can also be documented on an API resource's `toArray()`: its
`@responseField` tags are read for endpoints documented with `@apiResource` or
`@apiResourceCollection`, and its `#[ResponseField]` attributes for endpoints
documented with `#[ResponseFromApiResource]`. A resource with a `$wrap` key
prefixes the names with it.

## PHP attributes

Each attribute in `Ipsocode\Scribe\Attributes` has a tag counterpart. Attributes
are read from the controller class, the method's FormRequest class and the
method, and for the same parameter the method's wins.

| Attribute | Tag |
|---|---|
| `#[Group(name, description, authenticated)]` | `@group` |
| `#[Subgroup(name, description)]` | `@subgroup`, `@subgroupDescription` |
| `#[Endpoint(title, description, authenticated)]` | the docblock's title and description |
| `#[Authenticated]`, `#[Unauthenticated]` | `@authenticated`, `@unauthenticated` |
| `#[Deprecated(deprecated)]` | `@deprecated` |
| `#[UrlParam]`, `#[QueryParam]`, `#[BodyParam]` | `@urlParam`, `@queryParam`, `@bodyParam` |
| `#[Header(name, example)]` | `@header` |
| `#[Response(content, status, description)]` | `@response` |
| `#[ResponseFromFile(file, status, merge, description)]` | `@responseFile` |
| `#[ResponseFromApiResource(...)]` | `@apiResource` and friends |
| `#[ResponseFromTransformer(...)]` | `@transformer` and friends |
| `#[ResponseField]` | `@responseField` |

The parameter attributes take `name`, `type` (default `'string'`), `description`,
`required` (default `true`), `example`, `enum` (a list or a backed enum class),
`nullable` and `deprecated`. Pass `example: 'No-example'` to omit the example.
`#[Group]` and `#[Subgroup]` accept a backed enum as the name.

```php
use Ipsocode\Scribe\Attributes\Endpoint;
use Ipsocode\Scribe\Attributes\Group;
use Ipsocode\Scribe\Attributes\QueryParam;
use Ipsocode\Scribe\Attributes\ResponseFromApiResource;

#[Group('Users', 'Endpoints for reading users.')]
class UserController extends Controller
{
    #[Endpoint('List users', 'Returns every user.')]
    #[QueryParam('per_page', 'integer', 'Users per page.', required: false, example: 15)]
    #[QueryParam('cursor', 'string', 'An opaque cursor.', example: 'No-example')]
    #[ResponseFromApiResource(UserResource::class, User::class, collection: true)]
    public function index(): mixed
```

`#[ResponseFromApiResource]` takes `name`, `model`, `status`, `description`,
`collection`, `factoryStates`, `with`, `paginate`, `simplePaginate`,
`cursorPaginate`, `additional`, `withCount` and `call` (parameterless methods to
call on the resource before rendering it). `#[ResponseFromTransformer]` takes
`name`, `model`, `status`, `description`, `collection`, `factoryStates`, `with`,
`resourceKey` and `paginate` (`[AdapterClass::class, perPage]`).

## Validation rules

### FormRequest

A method that type-hints a `Hypervel\Foundation\Http\FormRequest` is documented
from its `rules()`, or from the rules of its `validator()` method when it has
one. The parameters' types, whether they are required, and examples are
inferred from the rules. Descriptions and examples come from `bodyParameters()`:

```php
class StorePostRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:120',
            'published' => 'boolean',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'title' => ['description' => 'The title of the post.', 'example' => 'My first post'],
            'published' => ['description' => 'Whether the post is publicly visible.', 'example' => false],
        ];
    }
}
```

A FormRequest documents query parameters instead when it has a
`queryParameters()` method or its docblock contains the words "Query
parameters". `Scribe::instantiateFormRequestUsing()` changes how the FormRequest
is built; see [hooks](hooks.md).

### Inline validators

These are read from the method's source, without running it:

```php
$validated = $request->validate([...]);
$validated = $request->validateWithBag('bag', [...]);
$validated = Request::validate([...]);
$validator = Validator::make($request->all(), [...]);
$validated = $this->validate($request, [...]);
```

- The validator has to be a statement of its own, assigned or not, within the
  method's first ten statements. `return $request->validate([...]);` is not
  documented.
- The request variable is named `$request` or `$req`, the facade is written
  `Request`, and the validator class name ends in `Validator`.
- The rules can be an array literal or a variable assigned earlier in the
  method. Keys must be string literals. The rules for a key are read when they
  are a string, or an array of strings, `new Enum(...)` and `Rule::enum(...)`;
  other entries in the array are skipped, and a key whose rules are written any
  other way is documented with no rules.
- A comment above a rule is its description. `Example: <value>` in it sets the
  example and `No-example` omits it.
- A `// Query parameters` comment above the statement makes the rules query
  parameters instead of body parameters.

```php
public function arrayRules(Request $request): array
{
    $validated = $request->validate([
        // How many to return. Example: 25
        'per_page' => ['required', 'integer'],
        // The status. No-example
        'status' => ['nullable', new Enum(PostStatus::class)],
    ]);

    return ['data' => $validated];
}
```

The tag and attribute strategies run after the validation-rule strategies, so a
`@bodyParam` for a validated field fills in or overrides what the rules said.

## Inherited methods

An action defined on a base controller has one docblock for every controller
that extends it. A static `inheritedDocsOverrides()` method on the controller
adds to it, keyed by method name and then by stage (`metadata`, `headers`,
`urlParameters`, `queryParameters`, `bodyParameters`, `responses`,
`responseFields`):

```php
use Ipsocode\Camel\Extraction\ExtractedEndpointData;

public static function inheritedDocsOverrides(): array
{
    return [
        'listed' => [
            'headers' => ['X-Inherited' => 'from the base'],
            'queryParameters' => [
                'inherited' => [
                    'type' => 'string',
                    'description' => 'Declared on the base controller.',
                    'example' => 'yes',
                    'required' => true,
                ],
            ],
            'responses' => [
                ['status' => 201, 'content' => '{"ok":true}', 'description' => 'Created'],
            ],
        ],
        'computed' => [
            'headers' => fn (ExtractedEndpointData $endpointData) => ['X-Endpoint' => $endpointData->uri],
        ],
    ];
}
```

An array is applied over what the strategies extracted for that stage: each
parameter, field, header or metadata key is set by name, and responses are
added. A closure receives the `ExtractedEndpointData` and its return value
replaces the stage. Child controllers inherit the static method and can
redefine it.

## Custom endpoints

Endpoints that are not routes of the application go in YAML files named
`custom.*.yaml` in `.scribe/endpoints/`. When there are none,
`scribe:generate` writes `custom.0.yaml` there, a commented-out example copied
from [`resources/example_custom_endpoint.yaml`](../resources/example_custom_endpoint.yaml).
Each file holds a list of endpoints:

```yaml
- httpMethods:
    - POST
  uri: api/doSomething/{param}
  metadata:
    groupName: Things
    title: Do something
    description: 'This endpoint allows you to do something.'
    authenticated: false
  headers:
    Content-Type: application/json
  urlParameters:
    param:
      name: param
      description: A URL param.
      required: true
      example: 2
      type: integer
  bodyParameters:
    something:
      name: something
      description: The things we should do.
      required: true
      example:
        - string 1
      type: 'string[]'
  responses:
    - status: 200
      description: 'When the thing was done smoothly.'
      content: '{"hey": "ho ho ho"}'
```

A custom endpoint joins the group named by `metadata.groupName`, or
`groups.default`, creating it if it does not exist. Custom files are never
overwritten once they contain an endpoint, and `--force` leaves them alone.
