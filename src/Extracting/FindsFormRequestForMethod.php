<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting;

use Hypervel\Foundation\Http\FormRequest;
use ReflectionClass;
use ReflectionException;
use ReflectionFunctionAbstract;
use ReflectionNamedType;

trait FindsFormRequestForMethod
{
    /**
     * @return ReflectionClass<FormRequest>|null
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

            try {
                $argumentClass = new ReflectionClass($argumentClassName);
                // @codeCoverageIgnoreStart
                // class_exists() has already loaded the class, so reflecting it
                // cannot fail.
            } catch (ReflectionException $e) { // @phpstan-ignore catch.neverThrown (class_exists() has loaded the class; kept in case that call site changes)
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
