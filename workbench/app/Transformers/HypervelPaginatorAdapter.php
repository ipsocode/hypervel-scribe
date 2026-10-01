<?php

declare(strict_types=1);

namespace Workbench\App\Transformers;

use Hypervel\Pagination\LengthAwarePaginator;
use League\Fractal\Pagination\PaginatorInterface;

/**
 * A Fractal paginator adapter for Hypervel's paginator.
 *
 * Fractal's bundled `IlluminatePaginatorAdapter` type-hints a pagination
 * contract that Hypervel's paginator does not implement, so an application
 * supplies an adapter like this one; `@transformerPaginator` takes any adapter
 * class name.
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
