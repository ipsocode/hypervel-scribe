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
use Ipsocode\Scribe\Exceptions\CouldntStartDatabaseTransaction;
use Ipsocode\Scribe\Exceptions\DatabaseTransactionsNotSupported;
use Ipsocode\Scribe\Extracting\DatabaseTransactionHelpers;
use Ipsocode\Scribe\Extracting\Strategies\Strategy;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils as c;
use Ipsocode\Scribe\Tools\ErrorHandlingUtils as e;
use Ipsocode\Scribe\Tools\Globals;
use Ipsocode\Scribe\Tools\Utils;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents a response by calling the route, with no annotation needed: builds
 * a request from the extracted parameters, dispatches it through the
 * application's HTTP kernel, and records what comes back.
 * See docs/ai/response-calls.md.
 */
class ResponseCalls extends Strategy
{
    use DatabaseTransactionHelpers;

    /**
     * Seconds to give one response call before the coroutine running it is canceled.
     *
     * The call runs in its own coroutine ({@see self::makeIsolatedCall()}), and
     * one that never returns would hang the whole generate. Raise it with the
     * `timeout` setting for an endpoint that legitimately takes longer.
     */
    public const float DEFAULT_TIMEOUT = 60.0;

    /** @var array<string, mixed> */
    protected array $previousConfigs = [];

    protected float $timeout = self::DEFAULT_TIMEOUT;

    /**
     * @param array<string, mixed> $settings
     * @return null|array<int, array<string, mixed>>
     */
    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        // No call when a success response is already documented.
        if ($endpointData->responses->hasSuccessResponse()) {
            return null;
        }

        return $this->makeResponseCall($endpointData, $settings);
    }

    /**
     * @param array<string, mixed> $settings
     * @return null|array<int, array<string, mixed>>
     */
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

        // The config is overridden only after the auth match, so an unknown auth
        // location throws before anything changes, and the `finally` puts it
        // back after every throw from here on.
        try {
            $this->configureEnvironment($settings);

            /** @var array<string, string> $fileParams */
            $fileParams = $settings['fileParams'] ?? [];
            $hardcodedFileParams = collect($fileParams)
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

            try {
                $response = $this->makeIsolatedCall($request, $endpointData);
            } catch (CouldntStartDatabaseTransaction|DatabaseTransactionsNotSupported $e) {
                // A connection that cannot be transacted is a configuration
                // error, not a failed call: the run stops rather than write.
                throw $e;
            } catch (Exception $e) {
                c::warn('Exception thrown during response call for ' . $endpointData->name());
                e::dumpExceptionIfVerbose($e);

                $response = null;
            }
        } finally {
            $this->rollbackConfigChanges();
        }

        return $response;
    }

    /**
     * The HTTP methods this route can be called with, best first.
     *
     * Delegates to `ExtractedEndpointData::getMethods()`, which strips the
     * automatic HEAD but keeps it when it is the only method the route answers,
     * so a HEAD-only route still has a method to call.
     *
     * @return array<int, string>
     */
    public function getMethods(Route $route): array
    {
        return ExtractedEndpointData::getMethods($route);
    }

    /**
     * @param array<int, string> $only The routes to apply this strategy to, and no others.
     *                                 Specify route names ("users.index", "users.*"), or method and path ("GET *", "POST /safe/*").
     * @param array<int, string> $except The routes not to apply this strategy to. A route matching both $only and $except is skipped.
     *                                   Specify route names ("users.index", "users.*"), or method and path ("GET *", "POST /safe/*").
     * @param array<string, mixed> $config Config values to set for the call. They are restored afterwards.
     * @param array<string, mixed> $queryParams Query params to always send with the response call. Key-value array.
     * @param array<string, mixed> $bodyParams Body params to always send with the response call. Key-value array.
     * @param array<string, string> $fileParams File params to always send with the response call. Key-value array. Key is param name, value is file path.
     * @param array<string, mixed> $cookies Cookies to always send with the response call. Key-value array.
     * @param float $timeout Seconds to allow the call before giving up on it. See self::DEFAULT_TIMEOUT.
     * @return array{string, array<string, mixed>} tuple of strategy class FQN and specified settings
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

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $urlParams
     * @param array<string, mixed> $bodyParams
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $fileParameters
     * @param array<string, mixed> $headers
     */
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
        if (Globals::$__beforeResponseCall !== null) {
            call_user_func_array(Globals::$__beforeResponseCall, [$request, $endpointData]);
        }
    }

    protected function runPostRequestHook(Request $request, ExtractedEndpointData $endpointData, mixed $response): void
    {
        if (Globals::$__afterResponseCall !== null) {
            call_user_func_array(Globals::$__afterResponseCall, [$request, $endpointData, $response]);
        }
    }

    /**
     * Make the call in a coroutine of its own, inside the
     * `database_connections_to_transact` transactions, and record the response.
     *
     * The child starts from a copy of the parent's context, so the request, user
     * and session the call leaves in context end with it. Borrowed database
     * connections are left out of the copy and the child borrows its own, so the
     * transactions are begun and rolled back here, on the connections the hooks,
     * the call and a streamed body write through.
     * See docs/ai/response-calls.md.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws Exception
     */
    protected function makeIsolatedCall(Request $request, ExtractedEndpointData $endpointData): array
    {
        return (new Waiter)->wait(function () use ($request, $endpointData) {
            try {
                $this->startDbTransaction();

                $this->runPreRequestHook($request, $endpointData);

                $response = $this->makeApiCall($request, $endpointData->route);

                $this->runPostRequestHook($request, $endpointData, $response);

                return [
                    [
                        'status' => $response->getStatusCode(),
                        'content' => $this->getContentFromResponse($response),
                        'headers' => $this->getResponseHeaders($response),
                    ],
                ];
            } finally {
                $this->endDbTransaction();
            }
        }, $this->timeout, copyContext: true);
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
     */
    protected function callHypervelRoute(Request $request): Response
    {
        /** @var Kernel $kernel */
        $kernel = app(Kernel::class);

        // The kernel sets this itself when it reaches the router, but the
        // global middleware runs first and `request()` has to work there too.
        RequestContext::set($request);

        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    /**
     * Transform headers array to array of $_SERVER vars with HTTP_* format.
     *
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
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

    /**
     * @return array<string, string>
     */
    protected function getResponseHeaders(Response $response): array
    {
        $headers = $response->headers->all();
        $formattedHeaders = [];

        foreach ($headers as $header => $values) {
            $formattedHeaders[$header] = implode('; ', $values);
        }

        return $formattedHeaders;
    }

    protected function getContentFromResponse(Response $response): ?string
    {
        if ($response instanceof BinaryFileResponse) {
            return '<<binary>> ' . $response->getFile()->getFilename();
        }

        if (! $response instanceof StreamedResponse) {
            $content = $response->getContent();

            return $content === false ? null : $content;
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

    /**
     * @param array<string, mixed> $settings
     */
    private function configureEnvironment(array $settings): void
    {
        $this->timeout = (float) ($settings['timeout'] ?? self::DEFAULT_TIMEOUT);

        $this->setConfigs($settings['config'] ?? []);
    }

    /**
     * @param array<string, mixed> $config
     */
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

    /**
     * @param null|array<string, mixed> $headers
     */
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

    /**
     * @param array<string, mixed> $query
     */
    private function addQueryParameters(Request $request, array $query): void
    {
        $request->query->add($query);
        $request->server->add(['QUERY_STRING' => http_build_query($query)]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function addBodyParameters(Request $request, array $body): void
    {
        $request->request->add($body);
    }
}
