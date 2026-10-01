<?php

declare(strict_types=1);

namespace Ipsocode\Camel\Extraction;

use Ipsocode\Camel\BaseDTO;

class ResponseField extends BaseDTO
{
    // Not a Parameter subclass: incoming response-field data is not normalised
    // enough for Parameter's typed API.

    /** @var string */
    public $name;

    /** @var string */
    public $description;

    /** @var string */
    public $type;

    /** @var bool */
    public $required;

    /** @var mixed */
    public $example;

    public array $enumValues = [];

    /** @var bool */
    public $nullable;
}
