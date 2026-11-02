<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Shared;

use Hypervel\Pagination\LengthAwarePaginator;
use League\Fractal\Manager;
use League\Fractal\Pagination\PaginatorInterface;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\Item;
use League\Fractal\TransformerAbstract;

class TransformerResponseTools
{
    /**
     * @param array<string, mixed> $pagination
     */
    public static function fetch(string $transformerClass, bool $isCollection, callable $modelInstantiator, array $pagination = [], ?string $resourceKey = null, ?string $serializer = null): string|false
    {
        $fractal = new Manager;

        if (! is_null($serializer)) {
            $fractal->setSerializer(app($serializer));
        }

        /** @var TransformerAbstract $transformer */
        $transformer = new $transformerClass;
        $modelInstance = $modelInstantiator();
        if ($isCollection) {
            $models = [$modelInstance, $modelInstantiator()];
            $resource = new Collection($models, $transformer, $resourceKey);

            ['adapter' => $paginatorAdapter, 'perPage' => $perPage] = $pagination;
            /** @var null|class-string<PaginatorInterface> $paginatorAdapter */
            if ($paginatorAdapter) {
                $total = count($models);
                // The adapter and paginator get only the first page; given every model,
                // they would show them all. $perPage is a string when it comes from the
                // `@transformerPaginator` tag, and slice() and the paginator want an int.
                $perPage = (int) $perPage;
                $firstPage = collect($models)->slice(0, $perPage);
                $resource = new Collection($firstPage, $transformer, $resourceKey);
                $paginator = new LengthAwarePaginator($firstPage, $total, $perPage);
                $resource->setPaginator(new $paginatorAdapter($paginator));
            }
        } else {
            $resource = (new Item($modelInstance, $transformer, $resourceKey));
        }

        return response($fractal->createData($resource)->toJson())->getContent();
    }
}
