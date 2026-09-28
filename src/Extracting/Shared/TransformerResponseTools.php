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
            // $resourceKey was passed to the Item and paginated-Collection
            // branches but not to this one, so `@transformerModel ...
            // resourceKey=posts` was silently ignored for an unpaginated
            // collection.
            $resource = new Collection($models, new $transformerClass, $resourceKey);

            ['adapter' => $paginatorAdapter, 'perPage' => $perPage] = $pagination;
            if ($paginatorAdapter) {
                $total = count($models);
                // Need to pass only the first page to both adapter and paginator, otherwise they will display ebverything
                // $perPage arrives as a string off the `@transformerPaginator`
                // tag; slice() and the paginator both want an int.
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
