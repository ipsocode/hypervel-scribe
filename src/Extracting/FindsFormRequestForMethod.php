<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting;

use Hypervel\Foundation\Http\FormRequest;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionNamedType;

trait FindsFormRequestForMethod
{
    /**
     * @return null|ReflectionClass<FormRequest>
     */
    protected function getFormRequestReflectionClass(ReflectionFunctionAbstract $method): ?ReflectionClass
    {
        foreach ($method->getParameters() as $argument) {
            $argType = $argument->getType();
            if (! $argType instanceof ReflectionNamedType) {
                continue;
            }

            $argumentClassName = $argType->getName();

            if (! class_exists($argumentClassName)) {
                continue;
            }

            $argumentClass = new ReflectionClass($argumentClassName);

            if ($argumentClass->isSubclassOf(FormRequest::class)) {
                return $argumentClass;
            }
        }

        return null;
    }
}
