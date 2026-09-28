<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies\Responses;

use Exception;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Http\Kernel;
use Hypervel\Coroutine\Waiter;
use Hypervel\Http\Request;
use Hypervel\Http\UploadedFile;
use Hypervel\Routing\Route;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Str;
use InvalidArgumentException;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\DatabaseTransactionHelpers;
use Ipsocode\Scribe\Extracting\Strategies\Strategy;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils as c;
use Ipsocode\Scribe\Tools\ErrorHandlingUtils as e;
use Ipsocode\Scribe\Tools\Globals;
use Ipsocode\Scribe\Tools\Utils;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Make a call to the route and retrieve its response.
 *
 * The only strategy that documents a response without the developer annotating
 * anything: it builds a request out of the parameters the earlier stages
 * extracted, dispatches it through the application's own HTTP kernel, and
 * records what comes back.
 */
class ResponseCalls extends Strategy
{
    use DatabaseTransactionHelpers;

    /**
     * Seconds to give one response call before the coroutine running it is cancelled.
     *
     * Hypervel-only: the call runs in its own coroutine (see
     * {@see self::callHypervelRoute()}), and a coroutine that never returns
     * would hang the whole generate. Upstream, which dispatches inline on
     * PHP-FPM, has nothing to bound. Raise it with the `timeout` setting for an
     * endpoint that legitimately takes longer.
     */
    public const float DEFAULT_TIMEOUT = 60.0;

    protected array $previousConfigs = [];

    protected float $timeout = self::DEFAULT_TIMEOUT;

    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        // Don't attempt a response call if there are already successful responses
        if ($endpointData->responses->hasSuccessResponse()) {
            return null;
        }

        return $this->makeResponseCall($endpointData, $settings);
    }

    public function makeResponseCall(ExtractedEndpointData $endpointData, array $settings): ?array
    {
        // Mix in parsed parameters with manually specified parameters.
        $bodyParameters = array_merge($endpointData->cleanBodyParameters, $settings['bodyParams'] ?? []);
        $queryParameters = array_merge($endpointData->cleanQueryParameters, $settings['queryParams'] ?? []);
        $urlParameters = $endpointData->cleanUrlParameters;
        $headers = $endpointData->headers;

        if ($endpointData->auth) {
            [$where, $name, $value] = $endpointData->auth;

            match ($where) {
                'queryParameters' => $queryParameters[$name] = $value,
                'bodyParameters' => $bodyParameters[$name] = $value,
                'headers' => $headers[$name] = $value,
                default => throw new InvalidArgumentException("Unknown auth location: {$where}"),
            };
        }

        // Upstream configures the environment first, so an auth location nobody
        // knows throws out of here with the transaction it opened still open and
        // the config overrides still applied. Placing this after the auth match
        // means that specific throw still can't leave anything dangling; the
        // `try`/`finally` below covers every throw from here on, including one
        // out of `configureEnvironment()` itself (e.g. a later connection in
        // `database_connections_to_transact` failing to start).
        try {
            $this->configureEnvironment($settings);

            $hardcodedFileParams = collect($settings['fileParams'] ?? [])
                ->map(fn (string $filePath) => new UploadedFile(
                    $filePath,
                    basename($filePath),
                    mime_content_type($filePath),
                    test: true
                ))
                ->toArray();
            $fileParameters = array_merge($endpointData->fileParameters, $hardcodedFileParams);

            $request = $this->prepareRequest(
                $endpointData->route,
                $endpointData->uri,
                $settings,
                $urlParameters,
                $bodyParameters,
                $queryParameters,
                $fileParameters,
                $headers
            );

            $this->runPreRequestHook($request, $endpointData);

            try {
                $response = $this->makeApiCall($request, $endpointData->route);

                $this->runPostRequestHook($request, $endpointData, $response);

                $response = [
                    [
                        'status' => $response->getStatusCode(),
                        'content' => $this->getContentFromResponse($response),
                        'headers' => $this->getResponseHeaders($response),
                    ],
                ];
            } catch (Exception $e) {
                c::warn('Exception thrown during response call for ' . $endpointData->name());
                e::dumpExceptionIfVerbose($e);

                $response = null;
            }
        } finally {
            $this->finish();
        }

        return $response;
    }

    /**
     * The HTTP methods this route can be called with, best first.
     *
     * Upstream strips HEAD unconditionally, which leaves a HEAD-only route with
     * no method left to call at all. `ExtractedEndpointData::getMethods()` is
     * the ported form of the same rule and keeps the method when it is the only
     * one the route answers.
     */
    public function getMethods(Route $route): array
    {
        return ExtractedEndpointData::getMethods($route);
    }

    /**
     * @param array $only The routes which this strategy should be applied to. Can not be specified with $except.
     *                    Specify route names ("users.index", "users.*"), or method and path ("GET *", "POST /safe/*").
     * @param array $except The routes which this strategy should be applied to. Can not be specified with $only.
     *                      Specify route names ("users.index", "users.*"), or method and path ("GET *", "POST /safe/*").
     * @param array $config any extra Hypervel config() values to set before starting the response call
     * @param array $queryParams Query params to always send with the response call. Key-value array.
     * @param array $bodyParams Body params to always send with the response call. Key-value array.
     * @param array $fileParams File params to always send with the response call. Key-value array. Key is param name, value is file path.
     * @param array $cookies Cookies to always send with the response call. Key-value array.
     * @param float $timeout Seconds to allow the call before giving up on it. Hypervel-only; see self::DEFAULT_TIMEOUT.
     */
    public static function withSettings(
        array $only = [],
        array $except = [],
        array $config = [],
        array $queryParams = [],
        array $bodyParams = [],
        array $fileParams = [
            // 'key' => 'storage/app/image.png',
        ],
        array $cookies = [],
        float $timeout = self::DEFAULT_TIMEOUT,
    ): array {
        return static::wrapWithSettings(
            only: $only,
            except: $except,
            otherSettings: compact(
                'config',
                'queryParams',
                'bodyParams',
                'fileParams',
                'cookies',
                'timeout',
            )
        );
    }

    protected function prepareRequest(
        Route $route,
        string $url,
        array $settings,
        array $urlParams,
        array $bodyParams,
        array $queryParams,
        array $fileParameters,
        array $headers,
    ): Request {
        $uri = Utils::getUrlWithBoundParameters($url, $urlParams);
        $routeMethods = $this->getMethods($route);
        $method = array_shift($routeMethods);
        $cookies = $settings['cookies'] ?? [];

        // Note that we initialise the request with the bodyParams here
        // and later still add them to the ParameterBag (`addBodyParameters`)
        // The first is so the body params get added to the request content
        // (where Hypervel reads body from)
        // The second is so they get added to the request bag
        // (where Symfony usually reads from and Hypervel sometimes does)
        // Adding to both ensures consistency

        // Always use the current app domain for response calls
        $rootUrl = config('app.url');

        // Built as a Symfony request and then promoted, the way the framework's
        // own test client does it: `Request::createFromBase()` is what aliases
        // the JSON payload onto the parameter bag, so a JSON body reads back
        // through `$request->input()` as well as `$request->json()`.
        $request = Request::createFromBase(SymfonyRequest::create(
            "{$rootUrl}/{$uri}",
            $method,
            [],
            $cookies,
            $fileParameters,
            $this->transformHeadersToServerVars($headers),
            json_encode($bodyParams)
        ));

        // Add headers again to catch any ones we didn't transform properly.
        $this->addHeaders($request, $route, $headers);
        $this->addQueryParameters($request, $queryParams);
        $this->addBodyParameters($request, $bodyParams);

        return $request;
    }

    protected function runPreRequestHook(Request $request, ExtractedEndpointData $endpointData): void
    {
        if (is_callable(Globals::$__beforeResponseCall)) {
            call_user_func_array(Globals::$__beforeResponseCall, [$request, $endpointData]);
        }
    }

    protected function runPostRequestHook(Request $request, ExtractedEndpointData $endpointData, mixed $response): void
    {
        if (is_callable(Globals::$__afterResponseCall)) {
            call_user_func_array(Globals::$__afterResponseCall, [$request, $endpointData, $response]);
        }
    }

    /**
     * @throws Exception
     */
    protected function makeApiCall(Request $request, Route $route): Response
    {
        return $this->callHypervelRoute($request);
    }

    /**
     * Dispatch the request through the application's HTTP kernel.
     *
     * The dispatch happens in a coroutine of its own rather than inline. A real
     * request handled by a Swoole worker gets a fresh coroutine, and everything
     * it leaves behind — the authenticated user, the session, the request
     * itself — lives in that coroutine's context and dies with it. Called
     * inline, one endpoint's response call would hand all of that to the next
     * one, and to the rest of the generate.
     *
     * The parent's context is copied in rather than started empty, because that
     * is what carries the database connection: `ConnectionResolver` keys
     * connections by coroutine, and a call that resolved its own would write
     * outside the transaction `database_connections_to_transact` opened here.
     */
    protected function callHypervelRoute(Request $request): Response
    {
        /** @var Kernel $kernel */
        $kernel = app(Kernel::class);

        return (new Waiter)->wait(function () use ($kernel, $request) {
            // The kernel sets this itself when it reaches the router, but the
            // global middleware runs first and `request()` has to work there too.
            RequestContext::set($request);

            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            return $response;
        }, $this->timeout, copyContext: true);
    }

    /**
     * Transform headers array to array of $_SERVER vars with HTTP_* format.
     */
    protected function transformHeadersToServerVars(array $headers): array
    {
        $server = [];
        $prefix = 'HTTP_';
        foreach ($headers as $name => $value) {
            $name = strtr(mb_strtoupper($name), '-', '_');
            if (! Str::startsWith($name, $prefix) && $name !== 'CONTENT_TYPE') {
                $name = $prefix . $name;
            }
            $server[$name] = $value;
        }

        return $server;
    }

    protected function getResponseHeaders(Response $response): array
    {
        $headers = $response->headers->all();
        $formattedHeaders = [];

        foreach ($headers as $header => $values) {
            $formattedHeaders[$header] = implode('; ', $values);
        }

        return $formattedHeaders;
    }

    protected function getContentFromResponse(Response $response): false|string
    {
        if (! $response instanceof StreamedResponse) {
            return $response->getContent();
        }

        // A streamed response has no content: it echoes as it goes, and only
        // once something calls `sendContent()`. Wrap its callback in an output
        // buffer to collect that.
        //
        // Upstream flushes the buffer on the way out, which prints the whole
        // response body into the middle of the generate's own output. The
        // handler here returns an empty string instead, so every chunk is
        // captured — including the ones an inner `ob_flush()` pushes out early
        // — and none of it reaches the console.
        $content = '';
        $originalCallback = $response->getCallback();
        $response->setCallback(function () use ($originalCallback, &$content) {
            ob_start(function (string $chunk) use (&$content): string {
                $content .= $chunk;

                return '';
            });

            try {
                $originalCallback();
            } finally {
                ob_end_flush();
            }
        });
        $response->sendContent();

        return $content;
    }

    private function configureEnvironment(array $settings): void
    {
        $this->timeout = (float) ($settings['timeout'] ?? self::DEFAULT_TIMEOUT);

        $this->startDbTransaction();
        $this->setConfigs($settings['config'] ?? []);
    }

    private function setConfigs(array $config): void
    {
        if (empty($config)) {
            return;
        }

        foreach ($config as $name => $value) {
            $this->previousConfigs[$name] = Config::get($name);
            Config::set([$name => $value]);
        }
    }

    private function rollbackConfigChanges(): void
    {
        foreach ($this->previousConfigs as $name => $value) {
            Config::set([$name => $value]);
        }
    }

    private function finish(): void
    {
        $this->endDbTransaction();
        $this->rollbackConfigChanges();
    }

    private function addHeaders(Request $request, Route $route, ?array $headers): void
    {
        // Set the proper domain
        if ($route->getDomain()) {
            $request->headers->add([
                'HOST' => $route->getDomain(),
            ]);
            $request->server->add([
                'HTTP_HOST' => $route->getDomain(),
                'SERVER_NAME' => $route->getDomain(),
            ]);
        }

        $headers = collect($headers);

        if (($headers->get('Accept') ?: $headers->get('accept')) === 'application/json') {
            $request->setRequestFormat('json');
        }
    }

    private function addQueryParameters(Request $request, array $query): void
    {
        $request->query->add($query);
        $request->server->add(['QUERY_STRING' => http_build_query($query)]);
    }

    private function addBodyParameters(Request $request, array $body): void
    {
        $request->request->add($body);
    }
}
