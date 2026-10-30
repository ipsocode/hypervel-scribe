<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Attributes;

use Attribute;
use Ipsocode\Scribe\Extracting\Shared\ResponseFileTools;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class ResponseFromFile
{
    /**
     * @param array<string, mixed>|string $merge
     */
    public function __construct(
        public string $file,
        public int $status = 200,
        public array|string $merge = [],
        public ?string $description = '',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'description' => $this->description,
            'content' => ResponseFileTools::getResponseContents($this->file, $this->merge),
        ];
    }
}
