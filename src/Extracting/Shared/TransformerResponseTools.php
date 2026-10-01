<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Shared;

use Hypervel\Pagination\LengthAwarePaginator;
use League\Fractal\Manager;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\Item;

class TransformerResponseTools
{
    public static function fetch(string $transformerClass, bool $isCollection, $modelInstantiator, array $pagination = [], ?string $resourceKey = null, ?string $serializer = null)
    {
        $fractal = new Manager;

        if (! is_null($serializer)) {
            $fractal->setSerializer(app($serializer));
        }

        $modelInstance = $modelInstantiator();
        if ($isCollection) {
            $models = [$modelInstance, $modelInstantiator()];
            $resource = new Collection($models, new $transformerClass, $resourceKey);

            ['adapter' => $paginatorAdapter, 'perPage' => $perPage] = $pagination;
            if ($paginatorAdapter) {
                $total = count($models);
                // The adapter and paginator get only the first page; given every model,
                // they would show them all. $perPage is a string when it comes from the
                // `@transformerPaginator` tag, and slice() and the paginator want an int.
                $perPage = (int) $perPage;
                $firstPage = collect($models)->slice(0, $perPage);
                $resource = new Collection($firstPage, new $transformerClass, $resourceKey);
                $paginator = new LengthAwarePaginator($firstPage, $total, $perPage);
                $resource->setPaginator(new $paginatorAdapter($paginator));
            }
        } else {
            $resource = (new Item($modelInstance, new $transformerClass, $resourceKey));
        }

        return response($fractal->createData($resource)->toJson())->getContent();
    }
}
