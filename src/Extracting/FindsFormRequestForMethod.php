<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting;

use Hypervel\Foundation\Http\FormRequest;
use ReflectionClass;
use ReflectionException;
use ReflectionFunctionAbstract;
use ReflectionUnionType;

trait FindsFormRequestForMethod
{
    protected function getFormRequestReflectionClass(ReflectionFunctionAbstract $method): ?ReflectionClass
    {
        foreach ($method->getParameters() as $argument) {
            $argType = $argument->getType();
            if ($argType === null || $argType instanceof ReflectionUnionType) {
                continue;
            }

            $argumentClassName = $argType->getName();

            if (! class_exists($argumentClassName)) {
                continue;
            }

            try {
                $argumentClass = new ReflectionClass($argumentClassName);
                // @codeCoverageIgnoreStart
                // class_exists() has already loaded the class, so reflecting it
                // cannot fail.
            } catch (ReflectionException $e) {
                continue;
            }
            // @codeCoverageIgnoreEnd

            if ($argumentClass->isSubclassOf(FormRequest::class)) {
                return $argumentClass;
            }
        }

        return null;
    }
}
