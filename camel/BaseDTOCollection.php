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
    /** The DTO class that array items are cast to. */
    public static string $base = '';

    public function __construct($items = [])
    {
        // Cast array items to the base DTO class.
        $items = array_map(
            fn ($item) => is_array($item) ? new static::$base($item) : $item,
            $items instanceof Collection ? $items->toArray() : $items
        );

        parent::__construct($items);
    }

    /**
     * Appends items, casting arrays to the base DTO class. Unlike
     * Collection::concat(), it mutates the collection in place.
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
