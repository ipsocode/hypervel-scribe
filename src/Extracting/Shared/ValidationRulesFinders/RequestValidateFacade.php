<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Shared\ValidationRulesFinders;

use Hypervel\Support\Facades\Request;
use PhpParser\Node;

/**
 * Finds the rules in a `Request::validate(...)` statement, assigned or not.
 * `Request::validateWithBag('', ...)` works too.
 */
class RequestValidateFacade
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
            $expr instanceof Node\Expr\StaticCall
            && $expr->class instanceof Node\Name
            && in_array($expr->class->name, ['Request', Request::class])
        ) {
            if ($expr->name instanceof Node\Identifier && $expr->name->name === 'validate') {
                $argument = $expr->args[0] ?? null;

                return $argument instanceof Node\Arg ? $argument->value : null;
            }

            if ($expr->name instanceof Node\Identifier && $expr->name->name === 'validateWithBag') {
                $argument = $expr->args[1] ?? null;

                return $argument instanceof Node\Arg ? $argument->value : null;
            }
        }

        return null;
    }
}
