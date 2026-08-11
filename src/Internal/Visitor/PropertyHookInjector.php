<?php

declare(strict_types=1);

namespace TypePHP\Internal\Visitor;

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
        if (! isset($node->hooks) || ! \is_array($node->hooks) || $node->hooks === []) {
            return;
        }

        $doc = $node->getDocComment();
        if ((bool) (Config::get()['respect_ignore_tags'] ?? true) && $doc !== null && (str_contains($doc->getText(), '@typephp-ignore') || str_contains($doc->getText(), '@typephp-disable'))) {
            return;
        }

        $propertyName = $node->props[0]->name->toString();

        foreach ($node->hooks as $hook) {
            $hookName = strtolower($hook->name->toString());

            if ($hookName === 'get') {
                if ($hook->body instanceof Node\Expr) {
                    $checkCall = NodeBuilder::createPropertyCheckCall($hook->body, new Node\Expr\Variable('this'), $propertyName);
                    $hook->body = NodeBuilder::createTernaryThrowExpr($checkCall);
                } elseif (\is_array($hook->body)) {
                    $hook->body = self::wrapHookReturnStatements($hook->body, $propertyName);
                }
            } elseif ($hookName === 'set') {
                $paramName = $hook->params !== [] && $hook->params[0]->var instanceof Node\Expr\Variable && \is_string($hook->params[0]->var->name)
                    ? $hook->params[0]->var->name
                    : 'value';

                $checkCall = NodeBuilder::createPropertyCheckCall(new Node\Expr\Variable($paramName), new Node\Expr\Variable('this'), $propertyName);
                $paramCheckStmt = new Node\Stmt\Expression(
                    new Node\Expr\Assign(
                        new Node\Expr\Variable($paramName),
                        NodeBuilder::createTernaryThrowExpr($checkCall)
                    )
                );
                $paramCheckStmt->setAttribute('typephp_injected', true);

                if (\is_array($hook->body)) {
                    array_unshift($hook->body, $paramCheckStmt);
                } elseif ($hook->body instanceof Node\Expr) {
                    $hook->body = [
                        $paramCheckStmt,
                        new Node\Stmt\Expression($hook->body),
                    ];
                }
            }
        }
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