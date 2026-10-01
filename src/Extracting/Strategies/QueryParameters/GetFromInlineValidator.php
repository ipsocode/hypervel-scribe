<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies\QueryParameters;

use Ipsocode\Scribe\Extracting\Strategies\GetFromInlineValidatorBase;
use PhpParser\Node;

class GetFromInlineValidator extends GetFromInlineValidatorBase
{
    protected function isValidationStatementMeantForThisStrategy(Node $validationStatement): bool
    {
        // The rules are query parameters only when a "// Query parameters" comment sits above them.
        $comments = $validationStatement->getComments();
        $comments = implode("\n", array_map(fn ($comment) => $comment->getReformattedText(), $comments));
        if (mb_strpos(mb_strtolower($comments), 'query parameters') !== false) {
            return true;
        }

        return false;
    }
}
