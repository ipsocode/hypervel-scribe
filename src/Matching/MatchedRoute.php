<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Matching;

use ArrayAccess;
use Hypervel\Routing\Route;

class MatchedRoute implements ArrayAccess
{
    protected Route $route;

    public function __construct(Route $route)
    {
        $this->route = $route;
    }

    public function getRoute(): Route
    {
        return $this->route;
    }

    public function offsetExists($offset): bool
    {
        return is_callable([$this, 'get' . ucfirst($offset)]);
    }

    public function offsetGet($offset): mixed
    {
        return call_user_func([$this, 'get' . ucfirst($offset)]);
    }

    public function offsetSet($offset, $value): void
    {
        $this->{$offset} = $value;
    }

    public function offsetUnset($offset): void
    {
        $this->{$offset} = null;
    }
}
