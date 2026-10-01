<?php

declare(strict_types=1);

namespace Ipsocode\Camel;

use ArrayAccess;
use Hypervel\Contracts\Support\Arrayable;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Throwable;

class BaseDTO implements ArrayAccess, Arrayable
{
    /** Extra properties an application can attach for its own use. */
    public array $custom = [];

    /**
     * @var array<class-string, array{nullable: list<string>, casts: array<string, null|class-string>}>
     */
    private static array $propertyMetadata = [];

    public function __construct(array $parameters = [])
    {
        $this->initializeProperties();

        foreach ($parameters as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $this->castProperty($key, $value);
            }
        }
    }

    public static function create(array|self $data, array|self $inheritFrom = []): static
    {
        if ($data instanceof static) {
            return $data;
        }

        $mergedData = $inheritFrom instanceof static ? $inheritFrom->toArray() : $inheritFrom;

        foreach ($data as $property => $value) {
            $mergedData[$property] = $value;
        }

        return new static($mergedData);
    }

    public function toArray(): array
    {
        $array = [];
        foreach (get_object_vars($this) as $property => $value) {
            $array[$property] = $value;
        }

        return $this->parseArray($array);
    }

    public static function make(array|self $data): static
    {
        return $data instanceof static ? $data : new static($data);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->{$offset});
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->{$offset};
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->{$offset} = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->{$offset});
    }

    public function except(string ...$keys): array
    {
        $array = [];
        foreach (get_object_vars($this) as $property => $value) {
            if (! in_array($property, $keys)) {
                $array[$property] = $value;
            }
        }

        return $this->parseArray($array);
    }

    public static function arrayOf(array $items): array
    {
        return array_map(function ($item) {
            return $item instanceof static ? $item : new static($item);
        }, $items);
    }

    protected function initializeProperties(): void
    {
        // Public properties with no default value but a nullable type start
        // out null, so reading one that was never passed does not fatal.
        foreach (self::propertyMetadata(static::class)['nullable'] as $name) {
            $this->{$name} = null;
        }
    }

    protected function castProperty(string $key, mixed $value): mixed
    {
        // Only arrays are cast.
        if (! is_array($value)) {
            return $value;
        }

        // The class the property is typed as, if it is one that exists.
        $className = self::propertyMetadata(static::class)['casts'][$key] ?? null;
        if ($className === null) {
            return $value;
        }

        if (is_subclass_of($className, self::class)) {
            return new $className($value);
        }

        // Any other class gets the array as its only argument, if it accepts one.
        try {
            return new $className($value);
        } catch (Throwable $e) {
            return $value;
        }
    }

    /**
     * What the constructor needs to know about a DTO class's properties,
     * reflected once per class per run because a run builds thousands of DTOs.
     *
     * @param class-string<self> $class
     * @return array{nullable: list<string>, casts: array<string, null|class-string>}
     */
    private static function propertyMetadata(string $class): array
    {
        if (isset(self::$propertyMetadata[$class])) {
            return self::$propertyMetadata[$class];
        }

        $reflection = new ReflectionClass($class);

        $nullable = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            // A property with a default value is already initialized.
            if ($property->hasDefaultValue()) {
                continue;
            }

            $type = $property->getType();
            if ($type && $type->allowsNull()) {
                $nullable[] = $property->getName();
            }
        }

        $casts = [];
        foreach ($reflection->getProperties() as $property) {
            $type = $property->getType();
            $casts[$property->getName()] = $type instanceof ReflectionNamedType
                && ! $type->isBuiltin()
                && class_exists($type->getName())
                    ? $type->getName()
                    : null;
        }

        return self::$propertyMetadata[$class] = ['nullable' => $nullable, 'casts' => $casts];
    }

    public static function flushState(): void
    {
        self::$propertyMetadata = [];
    }

    protected function parseArray(array $array): array
    {
        // Recurses so nested Arrayables, such as DTO collections, are converted too.
        foreach ($array as $key => $value) {
            if ($value instanceof Arrayable) {
                $array[$key] = $value->toArray();

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $array[$key] = $this->parseArray($value);
        }

        return $array;
    }
}
