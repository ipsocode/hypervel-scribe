<?php

declare(strict_types=1);

namespace Workbench\App\Transformers;

use Hypervel\Pagination\LengthAwarePaginator;
use League\Fractal\Pagination\PaginatorInterface;

/**
 * A Fractal paginator adapter for Hypervel's paginator.
 *
 * Fractal ships `IlluminatePaginatorAdapter`, but it type-hints Laravel's own
 * `LengthAwarePaginator` pagination contract, which Hypervel's paginator does
 * not implement — so the stock adapter cannot be used with this port. `@transformerPaginator` takes any adapter class name, so
 * an application supplies one like this.
 */
class HypervelPaginatorAdapter implements PaginatorInterface
{
    public function __construct(private LengthAwarePaginator $paginator)
    {
    }

    public function getCurrentPage(): int
    {
        return $this->paginator->currentPage();
    }

    public function getLastPage(): int
    {
        return $this->paginator->lastPage();
    }

    public function getTotal(): int
    {
        return $this->paginator->total();
    }

    public function getCount(): int
    {
        return $this->paginator->count();
    }

    public function getPerPage(): int
    {
        return $this->paginator->perPage();
    }

    public function getUrl(int $page): string
    {
        return $this->paginator->url($page);
    }
}
