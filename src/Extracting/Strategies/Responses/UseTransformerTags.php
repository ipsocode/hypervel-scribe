<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies\Responses;

use Exception;
use Hypervel\Support\Arr;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\DatabaseTransactionHelpers;
use Ipsocode\Scribe\Extracting\DocBlocks\DocBlockTag;
use Ipsocode\Scribe\Extracting\InstantiatesExampleModels;
use Ipsocode\Scribe\Extracting\RouteDocBlocker;
use Ipsocode\Scribe\Extracting\Shared\TransformerResponseTools;
use Ipsocode\Scribe\Extracting\Strategies\Strategy;
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

    /**
     * @param array<string, mixed> $routeRules
     * @return null|array<int, array{status: int, content: false|string}>
     */
    public function __invoke(ExtractedEndpointData $endpointData, array $routeRules = []): ?array
    {
        $methodDocBlock = RouteDocBlocker::getDocBlocksFromRoute($endpointData->route)['method'];
        $tags = $methodDocBlock->getTags();

        return $this->getTransformerResponseFromTags($tags);
    }

    /**
     * Get a response from the @transformer/@transformerCollection and @transformerModel tags.
     *
     * @param DocBlockTag[] $allTags
     * @return null|array<int, array{status: int, content: false|string}>
     */
    public function getTransformerResponseFromTag(DocBlockTag $transformerTag, array $allTags): ?array
    {
        [$statusCode, $transformerClass, $isCollection] = $this->getStatusCodeAndTransformerClass($transformerTag);
        [$model, $factoryStates, $relations, $resourceKey] = $this->getClassToBeTransformed($allTags);

        // @phpstan-ignore argument.type (an unknown transformer class throws ReflectionException, which is the intended failure)
        $modelInstantiator = fn () => $this->instantiateExampleModel($model, $factoryStates, $relations, (new ReflectionClass($transformerClass))->getMethod('transform'));
        $pagination = $this->getTransformerPaginatorData($allTags);
        $serializer = $this->config->get('fractal.serializer');

        $content = $this->inDbTransaction(fn () => TransformerResponseTools::fetch(
            $transformerClass,
            $isCollection,
            $modelInstantiator,
            $pagination,
            $resourceKey,
            $serializer
        ));

        return [
            [
                'status' => $statusCode ?: 200,
                'content' => $content,
            ],
        ];
    }

    /**
     * @param DocBlockTag[] $tags
     * @return null|array<int, array{status: int, content: false|string}>
     */
    public function getTransformerResponseFromTags(array $tags): ?array
    {
        if (empty($transformerTag = $this->getTransformerTag($tags))) {
            return null;
        }

        return $this->getTransformerResponseFromTag($transformerTag, $tags);
    }

    /**
     * @return array{int, string, bool}
     */
    private function getStatusCodeAndTransformerClass(DocBlockTag $tag): array
    {
        preg_match('/^(\d{3})?\s?([\s\S]*)$/', $tag->getContent(), $result);
        // @phpstan-ignore offsetAccess.notFound (the pattern matches any string, so both groups are set)
        $status = (int) ($result[1] ?: 200);
        $transformerClass = $result[2];
        $isCollection = mb_strtolower($tag->getName()) === 'transformercollection';

        return [$status, $transformerClass, $isCollection];
    }

    /**
     * @param DocBlockTag[] $tags
     * @return array{null|string, string[], string[], null|string}
     *
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

    /**
     * @param DocBlockTag[] $tags
     */
    private function getTransformerTag(array $tags): ?DocBlockTag
    {
        return Arr::first(Utils::filterDocBlockTags($tags, 'transformer', 'transformercollection'));
    }

    /**
     * The adapter class and page size from the `@transformerPaginator` tag, e.g.
     * `@transformerPaginator App\Transformers\HypervelPaginatorAdapter 15`. The
     * adapter wraps a Hypervel paginator, so Fractal's bundled one does not fit.
     *
     * @param DocBlockTag[] $tags
     * @return array{adapter: null|string, perPage: null|string}
     */
    private function getTransformerPaginatorData(array $tags): array
    {
        $tag = Arr::first(Utils::filterDocBlockTags($tags, 'transformerpaginator'));
        if (empty($tag)) {
            return ['adapter' => null, 'perPage' => null];
        }

        preg_match('/^\s*(.+?)(\s+\d+)?$/', $tag->getContent(), $result);
        // @phpstan-ignore offsetAccess.notFound (the pattern matches any string, so group 1 is set)
        $paginatorAdapter = $result[1];
        $perPage = $result[2] ?? null;
        if ($perPage) {
            $perPage = mb_trim($perPage);
        }

        return ['adapter' => $paginatorAdapter, 'perPage' => $perPage ?: null];
    }
}
