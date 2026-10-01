<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies\QueryParameters;

use Ipsocode\Scribe\Extracting\Strategies\GetFromFormRequestBase;
use ReflectionClass;

class GetFromFormRequest extends GetFromFormRequestBase
{
    protected string $customParameterDataMethodName = 'queryParameters';

    protected function isFormRequestMeantForThisStrategy(ReflectionClass $formRequestReflectionClass): bool
    {
        // A FormRequest documents query parameters when its docblock mentions
        // "Query parameters" or it has a queryParameters() method.
        $formRequestDocBlock = $formRequestReflectionClass->getDocComment() ?: '';
        if (mb_strpos(mb_strtolower($formRequestDocBlock), 'query parameters') !== false) {
            return true;
        }

        return parent::isFormRequestMeantForThisStrategy($formRequestReflectionClass);
    }
}
