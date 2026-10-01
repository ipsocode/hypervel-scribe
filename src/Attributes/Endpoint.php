<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class Endpoint
{
    public function __construct(
        public string $title,
        public ?string $description = '',
        /** Sets the endpoint's authentication, like #[Authenticated]; null leaves it unset. */
        public ?bool $authenticated = null,
    ) {
    }

    public function toArray()
    {
        $data = [
            'title' => $this->title,
            'description' => $this->description,
        ];
        if (! is_null($this->authenticated)) {
            $data['authenticated'] = $this->authenticated;
        }

        return $data;
    }
}
