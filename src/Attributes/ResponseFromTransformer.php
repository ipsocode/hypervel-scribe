<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Attributes;

use Attribute;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class ResponseFromTransformer
{
    /**
     * @param array<int, string> $factoryStates
     * @param array<int, string> $with
     * @param array{0: string, 1?: int}|array{} $paginate
     */
    public function __construct(
        public string $name,
        public ?string $model = null,
        public int $status = 200,
        public ?string $description = '',

        // Mark if this should be used as a collection. Only needed if not using a CollectionTransformer.
        public bool $collection = false,
        public array $factoryStates = [],
        public array $with = [],
        public ?string $resourceKey = null,

        // Format: [adapter, numberPerPage]. Example: [SomePaginator::class, 10]
        public array $paginate = [],
    ) {
    }
}
