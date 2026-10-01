<?php

declare(strict_types=1);

namespace Workbench\App\Transformers;

use League\Fractal\TransformerAbstract;

/**
 * A transformer whose `transform()` names no type.
 *
 * With no `@transformerModel` tag, the transform method's first parameter is
 * the only remaining clue about what to build — and here there isn't one, which
 * is the case Scribe has to complain about rather than guess at.
 */
class UntypedTransformer extends TransformerAbstract
{
    /**
     * @param mixed $model
     * @return array<string, mixed>
     */
    public function transform($model): array
    {
        return ['id' => $model->id ?? null];
    }
}
