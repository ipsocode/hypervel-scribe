<?php

declare(strict_types=1);

namespace Workbench\App\Transformers;

use League\Fractal\TransformerAbstract;

/**
 * A transformer whose `transform()` declares no parameter.
 *
 * With no `@transformerModel` tag there is no parameter type to infer a model
 * from either, so Scribe has to report that instead of failing on a null.
 */
class ParameterlessTransformer extends TransformerAbstract
{
    /**
     * @return array<string, mixed>
     */
    public function transform(): array
    {
        return [];
    }
}
