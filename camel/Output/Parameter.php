<?php

declare(strict_types=1);

namespace Ipsocode\Camel\Output;

class Parameter extends \Ipsocode\Camel\Extraction\Parameter
{
    public array $__fields = [];

    public function toArray(): array
    {
        return $this->except('__fields');
    }
}
