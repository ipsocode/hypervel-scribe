<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies\Responses;

use Exception;
use Hypervel\Support\Arr;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\DatabaseTransactionHelpers;
use Ipsocode\Scribe\Extracting\InstantiatesExampleModels;
use Ipsocode\Scribe\Extracting\RouteDocBlocker;
use Ipsocode\Scribe\Extracting\Shared\TransformerResponseTools;
use Ipsocode\Scribe\Extracting\Strategies\Strategy;
use Ipsocode\Scribe\Reflection\DocBlock\Tag;
use Ipsocode\Scribe\Tools\AnnotationParser as a;
use Ipsocode\Scribe\Tools\Utils;
use ReflectionClass;

/**
 * Builds a response from a @transformer or @transformerCollection tag.
 */
class UseTransformerTags extends Strategy
{
    use DatabaseTransactionHelpers;
    use InstantiatesExampleModels;

    public function __invoke(ExtractedEndpointData $endpointData, array $routeRules = []): ?array
    {
        $methodDocBlock = RouteDocBlocker::getDocBlocksFromRoute($endpointData->route)['method'];
        $tags = $methodDocBlock->getTags();

        return $this->getTransformerResponseFromTags($tags);
    }

    /**
     * Get a response from the @transformer/@transformerCollection and @transformerModel tags.
     *
     * @param Tag[] $allTags
     */
    public function getTransformerResponseFromTag(Tag $transformerTag, array $allTags): ?array
    {
        [$statusCode, $transformerClass, $isCollection] = $this->getStatusCodeAndTransformerClass($transformerTag);
        [$model, $factoryStates, $relations, $resourceKey] = $this->getClassToBeTransformed($allTags);

        $modelInstantiator = fn () => $this->instantiateExampleModel($model, $factoryStates, $relations, (new ReflectionClass($transformerClass))->getMethod('transform'));
        $pagination = $this->getTransformerPaginatorData($allTags);
        $serializer = $this->config->get('fractal.serializer');

        $this->startDbTransaction();
        $content = TransformerResponseTools::fetch(
            $transformerClass,
            $isCollection,
            $modelInstantiator,
            $pagination,
            $resourceKey,
            $serializer
        );
        $this->endDbTransaction();

        return [
            [
                'status' => $statusCode ?: 200,
                'content' => $content,
            ],
        ];
    }

    public function getTransformerResponseFromTags(array $tags): ?array
    {
        if (empty($transformerTag = $this->getTransformerTag($tags))) {
            return null;
        }

        return $this->getTransformerResponseFromTag($transformerTag, $tags);
    }

    private function getStatusCodeAndTransformerClass(Tag $tag): array
    {
        preg_match('/^(\d{3})?\s?([\s\S]*)$/', $tag->getContent(), $result);
        $status = (int) ($result[1] ?: 200);
        $transformerClass = $result[2];
        $isCollection = mb_strtolower($tag->getName()) === 'transformercollection';

        return [$status, $transformerClass, $isCollection];
    }

    /**
     * @throws Exception
     */
    private function getClassToBeTransformed(array $tags): array
    {
        $modelTag = Arr::first(Utils::filterDocBlockTags($tags, 'transformermodel'));

        $type = null;
        $states = [];
        $relations = [];
        $resourceKey = null;
        if ($modelTag) {
            ['content' => $type, 'fields' => $fields] = a::parseIntoContentAndFields($modelTag->getContent(), ['states', 'with', 'resourceKey']);
            $states = $fields['states'] ? explode(',', $fields['states']) : [];
            $relations = $fields['with'] ? explode(',', $fields['with']) : [];
            $resourceKey = $fields['resourceKey'] ?? null;
        }

        return [$type, $states, $relations, $resourceKey];
    }

    private function getTransformerTag(array $tags): ?Tag
    {
        return Arr::first(Utils::filterDocBlockTags($tags, 'transformer', 'transformercollection'));
    }

    /**
     * The adapter class and page size from the `@transformerPaginator` tag, e.g.
     * `@transformerPaginator App\Transformers\HypervelPaginatorAdapter 15`. The
     * adapter wraps a Hypervel paginator, so Fractal's bundled one does not fit.
     *
     * @param Tag[] $tags
     */
    private function getTransformerPaginatorData(array $tags): array
    {
        $tag = Arr::first(Utils::filterDocBlockTags($tags, 'transformerpaginator'));
        if (empty($tag)) {
            return ['adapter' => null, 'perPage' => null];
        }

        preg_match('/^\s*(.+?)(\s+\d+)?$/', $tag->getContent(), $result);
        $paginatorAdapter = $result[1];
        $perPage = $result[2] ?? null;
        if ($perPage) {
            $perPage = mb_trim($perPage);
        }

        return ['adapter' => $paginatorAdapter, 'perPage' => $perPage ?: null];
    }
}
