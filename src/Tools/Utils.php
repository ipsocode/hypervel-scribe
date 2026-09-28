<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tools;

use Closure;
use Exception;
use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Routing\Route;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Str;
use Ipsocode\Scribe\Exceptions\CouldntFindFactory;
use Ipsocode\Scribe\Exceptions\CouldntGetRouteDetails;
use Ipsocode\Scribe\Reflection\DocBlock\Tag;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils as c;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use RuntimeException;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

class Utils
{
    /**
     * Sometimes you have a config array that can have items as keys or values, like this:
     *
     * [
     *   'a',
     *   'b' => [ (options for b) ]
     * ]
     *
     * This method extracts the top-level options (['a', 'b'])
     */
    public static function getTopLevelItemsFromMixedConfigList(array $mixedList): array
    {
        $topLevels = [];
        foreach ($mixedList as $item => $value) {
            $topLevels[] = is_int($item) ? $value : $item;
        }

        return $topLevels;
    }

    public static function getUrlWithBoundParameters(string $uri, array $urlParameters = []): string
    {
        return self::replaceUrlParameterPlaceholdersWithValues($uri, $urlParameters);
    }

    /**
     * Transform parameters in URLs into real values (/users/{user} -> /users/2).
     * Uses @urlParam values specified by caller, otherwise just uses '1'.
     *
     * @param array $urlParameters Dictionary of url params and example values
     */
    public static function replaceUrlParameterPlaceholdersWithValues(string $uri, array $urlParameters): string
    {
        if (empty($urlParameters)) {
            return $uri;
        }

        foreach ($urlParameters as $parameterName => $example) {
            // Url parameter examples are typically ints (`@urlParam id integer
            // ... Example: 3`), and preg_replace()'s $replacement is string-only.
            $uri = preg_replace('#\{' . $parameterName . '\??}#', (string) $example, $uri);
        }

        // Remove unbound optional parameters with nothing
        $uri = preg_replace('#{([^/]+\?)}#', '', $uri);

        // Replace any unbound non-optional parameters with '1'
        return preg_replace('#{([^/]+)}#', '1', $uri);
    }

    public static function getRouteClassAndMethodNames(Route $route): array
    {
        $action = $route->getAction();

        $uses = $action['uses'];

        if ($uses !== null) {
            if (is_array($uses)) {
                return $uses;
            }
            if (is_string($uses)) {
                $usesArray = explode('@', $uses);
                if (count($usesArray) < 2) {
                    throw CouldntGetRouteDetails::new();
                }
                [$class, $method] = $usesArray;

                // Support for the Laravel Actions package, docblock should be put on the asController method
                if ($method === '__invoke' && method_exists($class, 'asController')) {
                    return [$class, 'asController'];
                }

                return [$class, $method];
            }
            if (static::isInvokableObject($uses)) {
                return [$uses, '__invoke'];
            }
        }
        if (array_key_exists(0, $action) && array_key_exists(1, $action)) {
            return [
                0 => $action[0],
                1 => $action[1],
            ];
        }

        throw new Exception("Couldn't get class and method names for route " . c::getRouteRepresentation($route) . '.');
    }

    /**
     * The file helpers below go through the framework's filesystem, so a path is
     * resolved the way PHP resolves any other: an absolute path as given, a
     * relative one against the working directory. Upstream rooted a Flysystem
     * adapter at getcwd() instead, which quietly turned an absolute path (an
     * absolute `--scribe-dir`, say) into one under the working directory.
     */
    public static function deleteDirectoryAndContents(string $dir): void
    {
        File::deleteDirectory($dir);
    }

    public static function copyDirectory(string $src, string $dest): void
    {
        if (! File::isDirectory($src)) {
            return;
        }

        // Creating the destination throws on its own; this is for a file that
        // could not be copied, which the framework reports only as `false`.
        if (! File::copyDirectory($src, $dest)) {
            throw new RuntimeException("Failed to copy [{$src}] to [{$dest}].");
        }
    }

    public static function makeDirectoryRecursive(string $dir): void
    {
        File::isDirectory($dir) || File::makeDirectory($dir, 0o777, true, true);
    }

    /**
     * Delete the files directly inside $dir for which $condition returns true.
     *
     * @param callable(SplFileInfo): bool $condition
     */
    public static function deleteFilesMatching(string $dir, callable $condition): void
    {
        if (! File::isDirectory($dir)) {
            return;
        }

        foreach (File::files($dir, hidden: true) as $file) {
            if ($condition($file) === true) {
                File::delete($file->getPathname());
            }
        }
    }

    /**
     * @param mixed $value
     */
    public static function isInvokableObject($value): bool
    {
        return is_object($value) && method_exists($value, '__invoke');
    }

    /**
     * Returns the route method or closure as an instance of ReflectionMethod or ReflectionFunction.
     *
     * @throws ReflectionException
     */
    public static function getReflectedRouteMethod(array $routeControllerAndMethod): ReflectionFunctionAbstract
    {
        if (count($routeControllerAndMethod) < 2) {
            throw CouldntGetRouteDetails::new();
        }
        [$class, $method] = $routeControllerAndMethod;

        if ($class instanceof Closure) {
            return new ReflectionFunction($class);
        }

        return (new ReflectionClass($class))->getMethod($method);
    }

    public static function isArrayType(string $typeName)
    {
        return Str::endsWith($typeName, '[]');
    }

    public static function getBaseTypeFromArrayType(string $typeName)
    {
        return mb_substr($typeName, 0, -2);
    }

    /**
     * @param string[] $states
     * @param string[] $relations
     * @return Factory
     *
     * @throws Throwable
     */
    public static function getModelFactory(string $modelName, array $states = [], array $relations = [])
    {
        // Factories are usually defined without the leading \ in the class name,
        // but the user might write it that way in a comment. Let's be safe.
        $modelName = mb_ltrim($modelName, '\\');

        if (method_exists($modelName, 'factory')) { // Laravel 8 type factory
            /** @var Factory $factory */
            $factory = call_user_func_array([$modelName, 'factory'], []);
            foreach ($states as $state) {
                if (method_exists(get_class($factory), $state)) {
                    $factory = $factory->{$state}();
                }
            }

            // Support nested relations; see https://github.com/knuckleswtf/scribe/pull/364 for a detailed example
            // Example: App\Models\Author with=posts.categories
            // Group relations by their first segment so that shared parents (e.g. 'order.status'
            // and 'order.delivery') are merged into a single factory call instead of overwriting
            // each other. See https://github.com/knuckleswtf/scribe/issues/991 for details.
            $groupedRelations = [];
            foreach ($relations as $relationPath) {
                $segments = explode('.', $relationPath);
                $firstSegment = array_shift($segments);
                if (! empty($segments)) {
                    $groupedRelations[$firstSegment][] = implode('.', $segments);
                } else {
                    // Direct relation without nesting — ensure the key exists
                    if (! isset($groupedRelations[$firstSegment])) {
                        $groupedRelations[$firstSegment] = [];
                    }
                }
            }

            foreach ($groupedRelations as $relationVector => $childRelations) {
                $relationInstance = (new $modelName)->{$relationVector}();
                $relationType = get_class($relationInstance);
                $relationModel = get_class($relationInstance->getModel());

                $factoryChain = self::getModelFactory($relationModel, $states, $childRelations);

                if ($relationInstance instanceof BelongsToMany) {
                    $pivot = method_exists($factory, 'pivot' . $relationVector)
                        ? $factory->{'pivot' . $relationVector}()
                        : [];

                    $factory = $factory->hasAttached($factoryChain, $pivot, $relationVector);
                } elseif ($relationType === BelongsTo::class) {
                    $factory = $factory->for($factoryChain, $relationVector);
                } else {
                    $factory = $factory->has($factoryChain, $relationVector);
                }
            }
        } else {
            try {
                $factory = factory($modelName);
            } catch (Throwable $e) {
                if (Str::contains($e->getMessage(), 'Call to undefined function Ipsocode\Scribe\Tools\factory()')) {
                    throw CouldntFindFactory::forModel($modelName);
                }

                throw $e; // @codeCoverageIgnore
            }
            // @codeCoverageIgnoreStart
            // Only reachable in a host application that still defines Laravel's
            // pre-8 global factory() helper. Hypervel does not, so the call
            // above can only raise "undefined function" in this package's suite.
            if (count($states)) {
                $factory = $factory->states($states);
            }
            // @codeCoverageIgnoreEnd
        }

        return $factory;
    }

    /**
     * Filter a list of docblock tags to those matching the specified ones (case-insensitive).
     *
     * @param Tag[] $tags
     * @return Tag[]
     */
    public static function filterDocBlockTags(array $tags, string ...$names): array
    {
        // Avoid "holes" in the keys of the filtered array by using array_values
        return array_values(
            array_filter($tags, fn ($tag) => in_array(mb_strtolower($tag->getName()), $names))
        );
    }

    /**
     * Like Hypervel's trans/__ function, but will fallback to using the default translation if translation fails.
     * For instance, if the user's locale is DE, but they have no DE strings defined,
     * Hypervel simply renders the translation key.
     * Instead, we render the EN version.
     */
    public static function trans(string $key, array $replace = [])
    {
        // Upstream lazily installs a "custom translation layer" here, through a
        // static flag and a method on its own service provider. This port needs
        // neither: the service provider registers the `scribe` namespace with
        // loadTranslationsFrom(), and the framework's FileLoader already merges
        // an application's `lang/vendor/scribe` overrides over it.
        $translation = trans($key, $replace);

        // @phpstan-ignore identical.alwaysFalse (trans() is typed never to return null; the guard is upstream's, for a translator that does)
        if ($translation === $key || $translation === null) {
            $translation = trans($key, $replace, 'en');
        }

        if ($translation === $key) {
            throw new Exception("Translation not found for {$key}. You can add a translation for this in your `lang/vendor/scribe/{locale}/scribe.php`, but this is likely a problem with the package. Please open an issue.");
        }

        return $translation;
    }
}
