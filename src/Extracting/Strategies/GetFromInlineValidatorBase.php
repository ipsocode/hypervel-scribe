<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies;

use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\MethodAstParser;
use Ipsocode\Scribe\Extracting\ParsesValidationRules;
use Ipsocode\Scribe\Extracting\Shared\ValidationRulesFinders\RequestValidate;
use Ipsocode\Scribe\Extracting\Shared\ValidationRulesFinders\RequestValidateFacade;
use Ipsocode\Scribe\Extracting\Shared\ValidationRulesFinders\ThisValidate;
use Ipsocode\Scribe\Extracting\Shared\ValidationRulesFinders\ValidatorMake;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use ReflectionMethod;

class GetFromInlineValidatorBase extends Strategy
{
    use ParsesValidationRules;

    public function __invoke(ExtractedEndpointData $endpointData, array $routeRules = []): ?array
    {
        if (! $endpointData->method instanceof ReflectionMethod) {
            return [];
        }

        $methodAst = MethodAstParser::getMethodAst($endpointData->method);
        [$validationRules, $customParameterData] = $this->lookForInlineValidationRules($methodAst);

        $bodyParametersFromValidationRules = $this->getParametersFromValidationRules($validationRules, $customParameterData);

        return $this->normaliseArrayAndObjectParameters($bodyParametersFromValidationRules);
    }

    public function lookForInlineValidationRules(ClassMethod $methodAst): array
    {
        // Validation usually comes early, so only the first 10 statements are searched.
        $statements = array_slice($methodAst->stmts, 0, 10);

        [$index, $validationStatement, $validationRules] = $this->findValidationExpression($statements);

        if ($validationStatement
            && ! $this->isValidationStatementMeantForThisStrategy($validationStatement)) {
            return [[], []];
        }

        // Rules held in a variable (like $rules) are read from its nearest
        // earlier assignment.
        if ($validationRules instanceof Node\Expr\Variable) {
            foreach (array_reverse(array_slice($statements, 0, $index)) as $earlierStatement) {
                if (
                    $earlierStatement instanceof Node\Stmt\Expression
                    && $earlierStatement->expr instanceof Node\Expr\Assign
                    && $earlierStatement->expr->var instanceof Node\Expr\Variable
                    && $earlierStatement->expr->var->name === $validationRules->name
                ) {
                    $validationRules = $earlierStatement->expr->expr;

                    break;
                }
            }
        }

        if (! $validationRules instanceof Node\Expr\Array_) {
            return [[], []];
        }

        $rules = [];
        $customParameterData = [];
        foreach ($validationRules->items as $item) {
            /** @var Node\ArrayItem $item */
            if (! $item->key instanceof Node\Scalar\String_) {
                continue;
            }

            $paramName = $item->key->value;

            // Only a string, or an array of strings and enum rules, is read; any
            // other expression leaves the parameter without rules.
            if ($item->value instanceof Node\Scalar\String_) {
                $rules[$paramName] = $item->value->value;
            } elseif ($item->value instanceof Node\Expr\Array_) {
                $rulesList = [];
                foreach ($item->value->items as $arrayItem) {
                    /** @var Node\ArrayItem $arrayItem */
                    if ($arrayItem->value instanceof Node\Scalar\String_) {
                        $rulesList[] = $arrayItem->value->value;
                    }
                    // An enum rule on a backed enum becomes an in: rule of its values.
                    elseif (
                        ($enum = $this->extractEnumClassFromArrayItem($arrayItem))
                        && enum_exists($enum) && method_exists($enum, 'tryFrom')
                    ) {
                        // @phpstan-ignore property.notFound (a tryFrom() enum is a BackedEnum, which has ->value)
                        $rulesList[] = 'in:' . implode(',', array_map(fn ($case) => $case->value, $enum::cases()));
                    }
                }
                $rules[$paramName] = implode('|', $rulesList);
            } else {
                $rules[$paramName] = [];
            }

            $dataFromComment = [];
            $comments = implode("\n", array_map(
                fn ($comment) => mb_ltrim(mb_ltrim($comment->getReformattedText(), '/')),
                $item->getComments()
            ));

            if ($comments) {
                if (str_contains($comments, 'No-example')) {
                    $dataFromComment['example'] = null;
                }

                $dataFromComment['description'] = mb_trim(str_replace(['No-example.', 'No-example'], '', $comments));
                if (preg_match('/(.*\s+|^)Example:\s*([\s\S]+)\s*/s', $dataFromComment['description'], $matches)) {
                    $dataFromComment['description'] = mb_trim($matches[1]);
                    $dataFromComment['example'] = $matches[2];
                }
            }

            $customParameterData[$paramName] = $dataFromComment;
        }

        return [$rules, $customParameterData];
    }

    protected function extractEnumClassFromArrayItem(Node\ArrayItem $arrayItem): ?string
    {
        $args = [];

        // Enum rule with the form "new Enum(...)"
        if ($arrayItem->value instanceof Node\Expr\New_
            && $arrayItem->value->class instanceof Node\Name
            && str_ends_with($arrayItem->value->class->name, 'Enum')
        ) {
            $args = $arrayItem->value->args;
        }

        // Enum rule with the form "Rule::enum(...)"
        elseif ($arrayItem->value instanceof Node\Expr\StaticCall
            && $arrayItem->value->class instanceof Node\Name
            && str_ends_with($arrayItem->value->class->name, 'Rule')
            && $arrayItem->value->name instanceof Node\Identifier
            && $arrayItem->value->name->name === 'enum'
        ) {
            $args = $arrayItem->value->args;
        }

        if (count($args) !== 1 || ! $args[0] instanceof Node\Arg) {
            return null;
        }

        $arg = $args[0];
        if ($arg->value instanceof Node\Expr\ClassConstFetch
            && $arg->value->class instanceof Node\Name
        ) {
            // The name resolver stores a Node\Name here, not a string, so it is
            // converted before the string check below.
            $resolved = $arg->value->class->getAttribute('resolvedName') ?? $arg->value->class;
            $className = $resolved instanceof Node\Name ? $resolved->toString() : (string) $resolved;

            // A namespaced name gets a leading '\'; a bare one is returned as-is
            // for enum_exists() to autoload.
            if (mb_strpos($className, '\\') !== false) {
                return '\\' . $className;
            }

            return $className;
        }
        if ($arg->value instanceof Node\Scalar\String_) {
            return $arg->value->value;
        }

        return null;
    }

    protected function getMissingCustomDataMessage($parameterName)
    {
        return "No extra data found for parameter '{$parameterName}' from your inline validator. You can add a comment above '{$parameterName}' with a description and example.";
    }

    protected function shouldCastUserExample()
    {
        return true;
    }

    protected function isValidationStatementMeantForThisStrategy(Node $validationStatement): bool
    {
        return true;
    }

    protected function findValidationExpression($statements): ?array
    {
        $strategies = [
            RequestValidate::class, // $request->validate(...);
            RequestValidateFacade::class, // Request::validate(...);
            ValidatorMake::class, // Validator::make($request, ...)
            ThisValidate::class, // $this->validate(...);
        ];

        foreach ($statements as $index => $node) {
            foreach ($strategies as $strategy) {
                if ($validationRules = $strategy::find($node)) {
                    return [$index, $node, $validationRules];
                }
            }
        }

        return [null, null, null];
    }
}
