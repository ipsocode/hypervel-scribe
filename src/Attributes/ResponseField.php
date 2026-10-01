<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Attributes;

use Attribute;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class ResponseField extends GenericParam
{
    // No default type: an omitted type is inferred from the first 2xx response.
    public function __construct(
        public string $name,
        public ?string $type = null,
        public ?string $description = '',
        public ?bool $required = true,
        public mixed $example = null, // Pass 'No-example' to omit the example
        public mixed $enum = null, // Can pass a list of values, or a native PHP enum
        public ?bool $nullable = false,
        public ?bool $deprecated = false,
    ) {
    }
}
