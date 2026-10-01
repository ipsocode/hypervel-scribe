<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Shared\ValidationRulesFinders;

use PhpParser\Node;

/**
 * Finds the rules in a `$request->validate(...)` statement, assigned or not.
 * The variable may also be `$req`, and `->validateWithBag('', ...)` works too.
 */
class RequestValidate
{
    public static function find(Node $node)
    {
        if (! $node instanceof Node\Stmt\Expression) {
            return;
        }

        $expr = $node->expr;
        if ($expr instanceof Node\Expr\Assign) {
            $expr = $expr->expr; // If it's an assignment, get the expression on the RHS
        }

        if (
            $expr instanceof Node\Expr\MethodCall
            && $expr->var instanceof Node\Expr\Variable
            && in_array($expr->var->name, ['request', 'req'])
        ) {
            if ($expr->name->name === 'validate') {
                return $expr->args[0]->value;
            }

            if ($expr->name->name === 'validateWithBag') {
                return $expr->args[1]->value;
            }
        }
    }
}
