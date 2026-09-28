<?php

declare(strict_types=1);

namespace Ipsocode\Camel;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Collection;
use Traversable;

/**
 * @template T of \Ipsocode\Camel\BaseDTO
 */
class BaseDTOCollection extends Collection
{
    /**
     * @var string the name of the base DTO class
     */
    public static string $base = '';

    public function __construct($items = [])
    {
        // Manually cast nested arrays
        $items = array_map(
            fn ($item) => is_array($item) ? new static::$base($item) : $item,
            $items instanceof Collection ? $items->toArray() : $items
        );

        parent::__construct($items);
    }

    /**
     * Append items to the collection, mutating it.
     *
     * Note: unlike the parent Collection::concat(), this override mutates the
     * collection in place and casts array items to the base DTO type.
     *
     * @param array[]|T[]|Traversable $source
     */
    public function concat(Traversable|array $source): static
    {
        foreach ($source as $item) {
            $this->push(is_array($item) ? new static::$base($item) : $item);
        }

        return $this;
    }

    public function toArray(): array
    {
        return array_map(
            fn ($item) => $item instanceof Arrayable ? $item->toArray() : $item,
            $this->items
        );
    }
}
