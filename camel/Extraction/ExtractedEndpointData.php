<?php

declare(strict_types=1);

namespace Ipsocode\Camel\Extraction;

use Hypervel\Http\UploadedFile;
use Hypervel\Routing\Route;
use Ipsocode\Camel\BaseDTO;
use Ipsocode\Scribe\Extracting\Shared\UrlParamsNormalizer;
use Ipsocode\Scribe\Tools\Globals;
use Ipsocode\Scribe\Tools\Utils as u;
use ReflectionClass;
use ReflectionException;
use ReflectionFunctionAbstract;

class ExtractedEndpointData extends BaseDTO
{
    /**
     * @var array<string>
     */
    public array $httpMethods;

    public string $uri;

    public Metadata $metadata;

    /**
     * @var array<string,string>
     */
    public array $headers = [];

    /**
     * @var array<string,Parameter>
     */
    public array $urlParameters = [];

    /**
     * @var array<string,mixed>
     */
    public array $cleanUrlParameters = [];

    /**
     * @var array<string,Parameter>
     */
    public array $queryParameters = [];

    /**
     * @var array<string,mixed>
     */
    public array $cleanQueryParameters = [];

    /**
     * @var array<string,Parameter>
     */
    public array $bodyParameters = [];

    /**
     * @var array<string,mixed>
     */
    public array $cleanBodyParameters = [];

    /**
     * @var array<string,array|UploadedFile>
     */
    public array $fileParameters = [];

    public ResponseCollection $responses;

    /**
     * @var array<string,ResponseField>
     */
    public array $responseFields = [];

    /**
     * Authentication info as [where, name, sample], e.g.
     * ["queryParameters", "api_key", "njiuyiw97865rfyvgfvb1"].
     */
    public array $auth = [];

    public ?ReflectionClass $controller;

    public ?ReflectionFunctionAbstract $method;

    public ?Route $route;

    public function __construct(array $parameters = [])
    {
        $parameters['metadata'] ??= new Metadata([]);
        $parameters['responses'] ??= new ResponseCollection([]);

        parent::__construct($parameters);

        $defaultNormalizer = fn () => UrlParamsNormalizer::normalizeParameterNamesInRouteUri($this->route, $this->method);
        $this->uri = match (is_callable(Globals::$__normalizeEndpointUrlUsing)) {
            true => call_user_func_array(
                Globals::$__normalizeEndpointUrlUsing,
                [$this->route->uri, $this->route, $this->method, $this->controller, $defaultNormalizer]
            ),
            default => $defaultNormalizer(),
        };
    }

    /**
     * @param array $extras only used for quick overrides in tests
     *
     * @throws ReflectionException
     */
    public static function fromRoute(Route $route, array $extras = []): self
    {
        $httpMethods = self::getMethods($route);
        $uri = $route->uri();

        [$controllerName, $methodName] = u::getRouteClassAndMethodNames($route);
        $controller = new ReflectionClass($controllerName);
        $method = u::getReflectedRouteMethod([$controllerName, $methodName]);

        $data = compact('httpMethods', 'uri', 'controller', 'method', 'route');
        $data = array_merge($data, $extras);

        return new self($data);
    }

    /**
     * @return array<string>
     */
    public static function getMethods(Route $route): array
    {
        $methods = $route->methods();

        // Hypervel adds HEAD to every GET route, so HEAD is dropped, unless it is
        // the route's only method.
        if (count($methods) === 1) {
            return $methods;
        }

        return array_diff($methods, ['HEAD']);
    }

    public function name()
    {
        return sprintf("[%s] {$this->route->uri}.", implode(',', $this->route->methods));
    }

    public function endpointId()
    {
        return $this->httpMethods[0] . str_replace(['/', '?', '{', '}', ':', '\\', '+', '|'], '-', $this->uri);
    }

    public function forSerialisation()
    {
        $copyArray = $this->except(
            // Derived data
            'cleanQueryParameters',
            'cleanUrlParameters',
            'fileParameters',
            'cleanBodyParameters',
            // Extraction-only data
            'route',
            'controller',
            'method',
            'auth',
        );
        // The group name and description are stored on the group.
        if (isset($copyArray['metadata']) && $copyArray['metadata'] instanceof Metadata) {
            $copyArray['metadata'] = $copyArray['metadata']->except('groupName', 'groupDescription');
        }

        return $copyArray;
    }
}
