<?php

declare(strict_types=1);

namespace Ipsocode\Camel\Extraction;

use Ipsocode\Camel\BaseDTO;

class Parameter extends BaseDTO
{
    public string $name;

    public ?string $description = null;

    public bool $required = false;

    public mixed $example = null;

    public string $type = 'string';

    public array $enumValues = [];

    public bool $exampleWasSpecified = false;

    public bool $nullable = false;

    public bool $deprecated = false;

    public function __construct(array $parameters = [])
    {
        unset($parameters['setter']);
        parent::__construct($parameters);
    }
}
