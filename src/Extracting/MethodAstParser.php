<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting;

use Exception;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionFunctionAbstract;
use Throwable;

/**
 * Parses route methods' source files and caches the ASTs, per file and per method.
 */
class MethodAstParser
{
    /**
     * @var array<string, Stmt\ClassMethod>
     */
    protected static array $methodAsts = [];

    /**
     * @var array<string, null|array<int, Stmt>>
     */
    protected static array $classAsts = [];

    public static function getMethodAst(ReflectionFunctionAbstract $method): ?Stmt\ClassMethod
    {
        $methodName = $method->name;
        /** @var string $fileName */
        $fileName = $method->getFileName();

        $methodAst = self::getCachedMethodAst($fileName, $methodName);

        if ($methodAst) {
            return $methodAst;
        }

        $classAst = self::getClassAst($fileName);
        $methodAst = self::findMethodInClassAst($classAst, $methodName);

        // A trait method imported under an alias is reflected by the alias, which the file doesn't declare.
        if ($methodAst === null) {
            return null;
        }

        self::cacheMethodAst($fileName, $methodName, $methodAst);

        return $methodAst;
    }

    /**
     * @return null|Stmt[]
     */
    protected static function parseClassSourceCode(string $sourceCode): ?array
    {
        $parser = (new ParserFactory)->createForHostVersion();

        try {
            $ast = $parser->parse($sourceCode);
        } catch (Throwable $error) {
            throw new Exception("Parse error: {$error->getMessage()}");
        }

        $traverser = new NodeTraverser(new NameResolver(options: ['replaceNodes' => false]));

        try {
            $traverser->traverse($ast);
        } catch (Throwable $error) {
            throw new Exception("Traverse error: {$error->getMessage()}");
        }

        return $ast;
    }

    /**
     * @param Stmt[] $ast
     */
    protected static function findMethodInClassAst(array $ast, string $methodName): ?Stmt\ClassMethod
    {
        $nodeFinder = new NodeFinder;

        /** @var null|Stmt\ClassMethod */
        return $nodeFinder->findFirst($ast, function (Node $node) use ($methodName) {
            // Only named class methods are found; closures are not handled.
            return $node instanceof Stmt\ClassMethod
                && $node->name->toString() === $methodName;
        });
    }

    protected static function getCachedMethodAst(string $fileName, string $methodName): ?Stmt\ClassMethod
    {
        $key = self::getAstCacheId($fileName, $methodName);

        return self::$methodAsts[$key] ?? null;
    }

    protected static function cacheMethodAst(string $fileName, string $methodName, Stmt\ClassMethod $methodAst): void
    {
        $key = self::getAstCacheId($fileName, $methodName);
        self::$methodAsts[$key] = $methodAst;
    }

    private static function getAstCacheId(string $fileName, string $methodName): string
    {
        return $fileName . '///' . $methodName;
    }

    /**
     * @return null|array<int, Stmt>
     */
    private static function getClassAst(string $fileName): ?array
    {
        $classAst = self::$classAsts[$fileName]
            ?? self::parseClassSourceCode(file_get_contents($fileName)); // @phpstan-ignore argument.type (the file was reflected from, so it exists)

        return self::$classAsts[$fileName] = $classAst;
    }

    public static function flushState(): void
    {
        self::$methodAsts = [];
        self::$classAsts = [];
    }
}
