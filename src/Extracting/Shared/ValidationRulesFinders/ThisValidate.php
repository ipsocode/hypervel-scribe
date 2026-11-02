<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Shared\ValidationRulesFinders;

use PhpParser\Node;

/**
 * Finds the rules in a `$this->validate($request, ...)` statement, assigned or
 * not. The request argument may have any name.
 */
class ThisValidate
{
    public static function find(Node $node): ?Node\Expr
    {
        if (! $node instanceof Node\Stmt\Expression) {
            return null;
        }

        $expr = $node->expr;
        if ($expr instanceof Node\Expr\Assign) {
            $expr = $expr->expr; // If it's an assignment, get the expression on the RHS
        }

        if (
            $expr instanceof Node\Expr\MethodCall
            && $expr->var instanceof Node\Expr\Variable
            && $expr->var->name === 'this'
        ) {
            if ($expr->name instanceof Node\Identifier && $expr->name->name === 'validate') {
                $argument = $expr->args[1] ?? null;

                return $argument instanceof Node\Arg ? $argument->value : null;
            }
        }

        return null;
    }
}
