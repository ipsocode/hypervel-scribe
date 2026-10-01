<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies\BodyParameters;

use Ipsocode\Scribe\Extracting\Strategies\GetFromFormRequestBase;
use ReflectionClass;

class GetFromFormRequest extends GetFromFormRequestBase
{
    protected string $customParameterDataMethodName = 'bodyParameters';

    protected function isFormRequestMeantForThisStrategy(ReflectionClass $formRequestReflectionClass): bool
    {
        // A FormRequest documents body parameters unless its docblock mentions
        // "Query parameters" or it has a queryParameters() method.
        $formRequestDocBlock = $formRequestReflectionClass->getDocComment() ?: '';
        if (mb_strpos(mb_strtolower($formRequestDocBlock), 'query parameters') !== false
            || $formRequestReflectionClass->hasMethod('queryParameters')) {
            return false;
        }

        return true;
    }
}
