<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use TypePHP\Internal\Config;

/**
 * @internal Injects property contract checks into PHP 8.4 get and set property hooks.
 */
final class PropertyHookInjector
{
    public static function process(Node\Stmt\Property $node): void
    {
        if (self::shouldSkipInjection($node)) {
            return;
        }

        $propertyName = $node->props[0]->name->toString();

        foreach ($node->hooks as $hook) {
            $hookName = strtolower($hook->name->toString());

            if ($hookName === 'get') {
                self::processGetHook($hook, $propertyName);
            } elseif ($hookName === 'set') {
                self::processSetHook($hook, $propertyName);
            }
        }
    }

    private static function shouldSkipInjection(Node\Stmt\Property $node): bool
    {
        if (! isset($node->hooks) || ! \is_array($node->hooks) || $node->hooks === []) {
            return true;
        }

        $doc = $node->getDocComment();
        if ($doc === null) {
            return false;
        }

        $shouldRespectIgnore = (bool) (Config::get()['respect_ignore_tags'] ?? true);

        return $shouldRespectIgnore && (str_contains($doc->getText(), '@typephp-ignore') || str_contains($doc->getText(), '@typephp-disable'));
    }

    private static function processGetHook(Node\PropertyHook $hook, string $propertyName): void
    {
        if ($hook->body instanceof Node\Expr) {
            $checkCall = NodeBuilder::createPropertyCheckCall($hook->body, new Node\Expr\Variable('this'), $propertyName);
            $hook->body = NodeBuilder::createTernaryThrowExpr($checkCall);
        } elseif (\is_array($hook->body)) {
            $hook->body = self::wrapHookReturnStatements($hook->body, $propertyName);
        }
    }

    private static function processSetHook(Node\PropertyHook $hook, string $propertyName): void
    {
        $paramName = self::extractSetParamName($hook);
        $checkCall = NodeBuilder::createPropertyCheckCall(new Node\Expr\Variable($paramName), new Node\Expr\Variable('this'), $propertyName);

        if (\is_array($hook->body)) {
            $paramCheckStmt = new Node\Stmt\Expression(
                new Node\Expr\Assign(
                    new Node\Expr\Variable($paramName),
                    NodeBuilder::createTernaryThrowExpr($checkCall)
                )
            );
            $paramCheckStmt->setAttribute('typephp_injected', value: true);
            array_unshift($hook->body, $paramCheckStmt);
        } elseif ($hook->body instanceof Node\Expr) {
            $hook->body = self::buildExpressionSetHookTernary($checkCall, $hook->body);
        }
    }

    private static function extractSetParamName(Node\PropertyHook $hook): string
    {
        return ($hook->params !== [] && $hook->params[0]->var instanceof Node\Expr\Variable && \is_string($hook->params[0]->var->name))
            ? $hook->params[0]->var->name
            : 'value';
    }

    private static function buildExpressionSetHookTernary(Node\Expr\FuncCall $checkCall, Node\Expr $assignmentExpr): Node\Expr\Ternary
    {
        return new Node\Expr\Ternary(
            new Node\Expr\Instanceof_(
                new Node\Expr\Assign(new Node\Expr\Variable('__typephpVal'), $checkCall),
                new Node\Name('\TypePHP\Internal\Diagnostic\ErrorMessage')
            ),
            new Node\Expr\Throw_(
                new Node\Expr\StaticCall(
                    new Node\Name('\TypePHP\Internal\Diagnostic\ErrorFactory'),
                    'prepareException',
                    [
                        new Node\Arg(
                            new Node\Expr\New_(
                                new Node\Name('\TypePHP\Exception\TypeError'),
                                [
                                    new Node\Arg(
                                        new Node\Expr\MethodCall(new Node\Expr\Variable('__typephpVal'), 'getMessage')
                                    ),
                                ]
                            )
                        ),
                    ]
                )
            ),
            $assignmentExpr
        );
    }

    /**
     * @param array<Node\Stmt> $stmts
     *
     * @return array<Node\Stmt>
     */
    private static function wrapHookReturnStatements(array $stmts, string $propertyName): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class ($propertyName) extends NodeVisitorAbstract {
            public function __construct(private string $propertyName)
            {
            }

            public function enterNode(Node $node): int|null
            {
                if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction || $node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Node\Stmt\Return_ && $node->expr !== null) {
                    $checkCall = NodeBuilder::createPropertyCheckCall($node->expr, new Node\Expr\Variable('this'), $this->propertyName);
                    $node->expr = NodeBuilder::createTernaryThrowExpr($checkCall);
                }

                return null;
            }
        });

        /** @var array<Node\Stmt> $newStmts */
        $newStmts = $traverser->traverse($stmts);

        return $newStmts;
    }
}
