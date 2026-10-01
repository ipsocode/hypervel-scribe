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
 * Documents a response by calling the route, with no annotation needed: builds
 * a request from the extracted parameters, dispatches it through the
 * application's HTTP kernel, and records what comes back.
 * See docs/design/response-calls.md.
 */
class ResponseCalls extends Strategy
{
    use DatabaseTransactionHelpers;

    /**
     * Seconds to give one response call before the coroutine running it is cancelled.
     *
     * The call runs in its own coroutine ({@see self::callHypervelRoute()}), and
     * one that never returns would hang the whole generate. Raise it with the
     * `timeout` setting for an endpoint that legitimately takes longer.
     */
    public const float DEFAULT_TIMEOUT = 60.0;

    protected array $previousConfigs = [];

    protected float $timeout = self::DEFAULT_TIMEOUT;

    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        // No call when a success response is already documented.
        if ($endpointData->responses->hasSuccessResponse()) {
            return null;
        }

        return $this->makeResponseCall($endpointData, $settings);
    }

    public function makeResponseCall(ExtractedEndpointData $endpointData, array $settings): ?array
    {
        // Parameters from the settings override the extracted ones.
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

        // The environment is configured only after the auth match, so an unknown
        // auth location throws before any transaction opens or config override
        // applies. The `try`/`finally` below covers every throw from here on,
        // including one out of `configureEnvironment()` itself (e.g. a later
        // connection in `database_connections_to_transact` failing to start).
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
     * Delegates to `ExtractedEndpointData::getMethods()`, which strips the
     * automatic HEAD but keeps it when it is the only method the route answers,
     * so a HEAD-only route still has a method to call.
     */
    public function getMethods(Route $route): array
    {
        return ExtractedEndpointData::getMethods($route);
    }

    /**
     * @param array $only The routes to apply this strategy to, and no others.
     *                    Specify route names ("users.index", "users.*"), or method and path ("GET *", "POST /safe/*").
     * @param array $except The routes not to apply this strategy to. A route matching both $only and $except is skipped.
     *                      Specify route names ("users.index", "users.*"), or method and path ("GET *", "POST /safe/*").
     * @param array $config Config values to set for the call. They are restored afterwards.
     * @param array $queryParams Query params to always send with the response call. Key-value array.
     * @param array $bodyParams Body params to always send with the response call. Key-value array.
     * @param array $fileParams File params to always send with the response call. Key-value array. Key is param name, value is file path.
     * @param array $cookies Cookies to always send with the response call. Key-value array.
     * @param float $timeout Seconds to allow the call before giving up on it. See self::DEFAULT_TIMEOUT.
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

        // Response calls always go to the current app URL.
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

        // The headers went in as server vars above; this adds the route's domain
        // and, for an `Accept: application/json` header, the JSON request format.
        $this->addHeaders($request, $route, $headers);
        $this->addQueryParameters($request, $queryParams);
        // The body is in the request content already, but a non-JSON request
        // reads its input from the request bag, so it goes there too.
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
     * Dispatches the request through the application's HTTP kernel in a
     * coroutine of its own, so the request, user and session it leaves in
     * context end with it. The child starts from a copy of the parent's context
     * without its database connections, so it resolves its own and its queries
     * can run outside the `database_connections_to_transact` transactions.
     * See docs/design/response-calls.md.
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

        // A streamed response echoes its content only when `sendContent()` runs,
        // so its callback runs in an output buffer whose handler collects every
        // chunk, including any an inner `ob_flush()` pushes out early, and
        // returns '' so none of it reaches the console.
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
        // A route bound to a domain is called on that domain.
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
