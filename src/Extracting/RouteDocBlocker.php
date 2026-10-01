<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting;

use Exception;
use Hypervel\Routing\Route;
use Ipsocode\Scribe\Reflection\DocBlock;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils as c;
use Ipsocode\Scribe\Tools\Utils as u;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionMethod;

/**
 * Parses and caches the docblocks of route classes and methods.
 *
 * Every docblock Scribe reads goes through here, not only a route's own: a
 * controller's class docblock is shared by all of its endpoints, and the tag
 * strategies read a FormRequest's or an API resource's docblock for each route
 * that uses it, so each is parsed once per run rather than once per route.
 */
class RouteDocBlocker
{
    protected static array $docBlocks = [];

    /**
     * @var array<string, DocBlock> keyed by class name
     */
    protected static array $classDocBlocks = [];

    /**
     * @var array<string, DocBlock> keyed by declaring class and method name
     */
    protected static array $methodDocBlocks = [];

    /**
     * @return array{method: DocBlock, class: ?DocBlock} Method and class docblocks
     */
    public static function getDocBlocksFromRoute(Route $route): array
    {
        [$className, $methodName] = u::getRouteClassAndMethodNames($route);

        return static::getDocBlocks($route, $className, $methodName);
    }

    /**
     * @param mixed $className a class name or handler instance, or a [class, method] pair
     * @param null|mixed $methodName the method name, unless $className is a pair
     * @return array{method: DocBlock, class: DocBlock} Method and class docblocks
     */
    public static function getDocBlocks(Route $route, $className, $methodName = null): array
    {
        if (is_array($className)) {
            [$className, $methodName] = $className;
        }

        $normalizedClassName = static::normalizeClassName($className);
        $docBlocks = self::getCachedDocBlock($route, $normalizedClassName, $methodName);

        if ($docBlocks) {
            return $docBlocks;
        }

        $class = new ReflectionClass($className);

        if (! $class->hasMethod($methodName)) {
            throw new Exception('Error while fetching docblock for route ' . c::getRouteRepresentation($route) . ": Class {$className} does not contain method {$methodName}");
        }

        $method = u::getReflectedRouteMethod([$className, $methodName]);

        $docBlocks = [
            'method' => static::forMethod($method),
            'class' => static::forClass($class),
        ];
        self::cacheDocBlocks($route, $normalizedClassName, $methodName, $docBlocks);

        return $docBlocks;
    }

    /**
     * The docblock of a class, parsed once per run.
     *
     * Keyed by class name, since a doc comment belongs to the class rather than
     * to an instance. A class with no doc comment gets an empty docblock:
     * `getDocComment()` returns false then, and false reaching the parser is a
     * TypeError under strict types, which no extraction `catch` handles.
     *
     * @param class-string|object|ReflectionClass $class
     */
    public static function forClass(object|string $class): DocBlock
    {
        $reflection = $class instanceof ReflectionClass ? $class : new ReflectionClass($class);

        return self::$classDocBlocks[$reflection->getName()]
            ??= new DocBlock($reflection->getDocComment() ?: '');
    }

    /**
     * The docblock of a method, parsed once per run.
     *
     * A closure has no name to key a cache on, and closure routes are rare, so
     * a function's docblock is parsed on every call.
     */
    public static function forMethod(ReflectionFunctionAbstract $method): DocBlock
    {
        if (! $method instanceof ReflectionMethod) {
            return new DocBlock($method->getDocComment() ?: '');
        }

        return self::$methodDocBlocks[$method->class . '::' . $method->getName()]
            ??= new DocBlock($method->getDocComment() ?: '');
    }

    /**
     * @param object|string $classNameOrInstance
     */
    protected static function normalizeClassName($classNameOrInstance): string
    {
        if (is_object($classNameOrInstance)) {
            // A route's handler instance lives as long as the route, which outlasts this
            // per-run cache, so its object id can't be reused for another handler.
            $classNameOrInstance = get_class($classNameOrInstance) . '::' . spl_object_id($classNameOrInstance);
        }

        return $classNameOrInstance;
    }

    protected static function getCachedDocBlock(Route $route, string $className, string $methodName)
    {
        $routeId = self::getRouteCacheId($route, $className, $methodName);

        return self::$docBlocks[$routeId] ?? null;
    }

    protected static function cacheDocBlocks(Route $route, string $className, string $methodName, array $docBlocks)
    {
        $routeId = self::getRouteCacheId($route, $className, $methodName);
        self::$docBlocks[$routeId] = $docBlocks;
    }

    private static function getRouteCacheId(Route $route, string $className, string $methodName): string
    {
        return $route->uri()
            . ':'
            . implode(array_diff($route->methods(), ['HEAD']))
            . $className
            . $methodName;
    }

    public static function flushState(): void
    {
        self::$docBlocks = [];
        self::$classDocBlocks = [];
        self::$methodDocBlocks = [];
    }
}
