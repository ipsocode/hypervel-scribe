<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Attributes;

use Attribute;
use Hypervel\Http\Resources\Json\ResourceCollection;
use Ipsocode\Scribe\Extracting\Shared\ApiResourceResponseTools;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class ResponseFromApiResource
{
    public function __construct(
        public string $name,
        public ?string $model = null,
        public int $status = 200,
        public ?string $description = '',

        // Mark if this should be used as a collection. Only needed if not using a ResourceCollection.
        public ?bool $collection = null,
        public array $factoryStates = [],
        public array $with = [],
        public ?int $paginate = null,
        public ?int $simplePaginate = null,
        public ?int $cursorPaginate = null,
        public array $additional = [],
        public array $withCount = [],

        // Parameterless fluent methods to invoke on the built resource before it is
        // rendered (e.g. ['withDetails']). Lets an endpoint document a resource variant
        // that is only reachable via a fluent call the controller makes at runtime —
        // something the constructor-only instantiation here cannot otherwise express.
        public array $call = [],
    ) {
    }

    public function modelToBeTransformed(): ?string
    {
        if (! empty($this->model)) {
            return $this->model;
        }

        return ApiResourceResponseTools::tryToInferApiResourceModel($this->name);
    }

    public function isCollection(): bool
    {
        if (! is_null($this->collection)) {
            return $this->collection;
        }

        return is_subclass_of($this->name, ResourceCollection::class);
    }
}
