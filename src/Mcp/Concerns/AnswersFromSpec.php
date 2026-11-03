<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Mcp\Concerns;

use Closure;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Ipsocode\Scribe\Exceptions\CouldntReadOpenApiSpec;

trait AnswersFromSpec
{
    /**
     * Return the answer, or a tool error naming the fix when the spec cannot be read.
     *
     * @param Closure(): (Response|ResponseFactory) $answer
     */
    protected function answerFromSpec(Closure $answer): Response|ResponseFactory
    {
        try {
            return $answer();
        } catch (CouldntReadOpenApiSpec $exception) {
            return Response::error($exception->getMessage());
        }
    }
}
