<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Shared\ValidationRulesFinders;

use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Finds the rules in a `Validator::make($request, ...)` call anywhere in a
 * statement, such as `$validator = Validator::make($request, ...)` or
 * `Validator::make($request, ...)->validate()`. Variable names don't matter,
 * and any class name ending in Validator matches.
 */
class ValidatorMake
{
    public static function find(Node $node)
    {
        // Only expression statements are searched
        if (! $node instanceof Node\Stmt\Expression) {
            return;
        }

        $validatorNode = (new NodeFinder)->findFirst($node, function ($node): bool {
            return $node instanceof Node\Expr\StaticCall
                && ! empty($node->class->name)
                && str_ends_with($node->class->name, 'Validator')
                && $node->name->name === 'make';
        });

        if ($validatorNode instanceof Node\Expr\StaticCall) {
            return $validatorNode->args[1]->value;
        }
    }
}
