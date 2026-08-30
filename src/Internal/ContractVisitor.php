<?php

declare(strict_types=1);

namespace TypePHP\Internal;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use TypePHP\Contract\DocblockExtractor;
use TypePHP\Internal\Visitor\FunctionContractInjector;
use TypePHP\Internal\Visitor\NodeBuilder;
use TypePHP\Internal\Visitor\PropertyHookInjector;
use TypePHP\Internal\Visitor\ScopeManager;

/**
 * @internal AST Node Visitor that injects contract checks, scope tracking, property hook validation, and parameter/return wrappers into functions and methods.
 */
final class ContractVisitor extends NodeVisitorAbstract
{
    private ScopeManager $scopeManager;

    public function __construct()
    {
        $this->scopeManager = new ScopeManager();
    }

    /**
     * Traverses and transforms AST nodes during entry.
     *
     * @return array<Node>|null
     */
    public function enterNode(Node $node): ?array
    {
        if ($node instanceof Node\Stmt\Function_
            || $node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction
            || $node instanceof Node\Stmt\If_
            || $node instanceof Node\Stmt\Else_
            || $node instanceof Node\Stmt\ElseIf_
            || $node instanceof Node\Stmt\Foreach_
            || $node instanceof Node\Stmt\While_
            || $node instanceof Node\Stmt\For_
            || $node instanceof Node\Stmt\Do_
            || $node instanceof Node\Stmt\TryCatch
        ) {
            $this->scopeManager->pushScope();
        }

        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
            FunctionContractInjector::inject($node);

            return null;
        }

        if ($node instanceof Node\Stmt\Property) {
            PropertyHookInjector::process($node);

            return null;
        }

        if ($node instanceof Node\Stmt\Return_ && $node->expr !== null) {
            $doc = $node->getDocComment();
            if ($doc !== null && str_contains($doc->getText(), '@var')) {
                $extracted = DocblockExtractor::extractVarTagFromDoc($doc->getText());
                if ($extracted !== null) {
                    [$typeString, $varName] = $extracted;
                    $isApplicableToReturn = ($varName === '')
                        || ($node->expr instanceof Node\Expr\Variable && $node->expr->name === $varName);

                    if ($isApplicableToReturn) {
                        $effectiveVarName = ($varName !== '') ? $varName : 'return';
                        $checkCall = NodeBuilder::createVariableCheckCall($node->expr, $typeString, $effectiveVarName);
                        $node->expr = NodeBuilder::createTernaryThrowExpr($checkCall, $node->getStartLine());
                        $node->setAttribute('typephp_var_wrapped', true);
                    }
                }
            }
        }

        if ($node instanceof Node\Stmt\Expression) {
            $doc = $node->getDocComment();
            if ($doc !== null && str_contains($doc->getText(), '@var')) {
                $this->scopeManager->extractVarDocblock($doc->getText(), $node->expr);
            }

            if ($node->expr instanceof Node\Expr\Assign) {
                $assign = $node->expr;
                if ($assign->var instanceof Node\Expr\List_ || ($assign->var instanceof Node\Expr\Array_ && $assign->var->getAttribute('kind') === Node\Expr\Array_::KIND_SHORT)) {
                    $destructuredVars = $this->extractDestructuringVariables($assign->var);
                    $checkStmts = [];

                    foreach ($destructuredVars as $dVar) {
                        $varName = $dVar['varName'];
                        $typeString = $this->scopeManager->getVarTypeFromScope($varName);

                        if ($typeString !== null) {
                            $checkCall = NodeBuilder::createVariableCheckCall($dVar['expr'], $typeString, $varName);
                            $checkStmt = new Node\Stmt\Expression(
                                new Node\Expr\Assign(
                                    $dVar['expr'],
                                    NodeBuilder::createTernaryThrowExpr($checkCall, $dVar['expr']->getStartLine())
                                )
                            );
                            $checkStmt->setAttribute('typephp_injected', value: true);
                            $checkStmts[] = $checkStmt;
                        }
                    }

                    if ($checkStmts !== []) {
                        return array_merge([$node], $checkStmts);
                    }
                }
            }
        }

        if ($node instanceof Node\Stmt\Foreach_) {
            $doc = $node->getDocComment();
            if ($doc !== null && str_contains($doc->getText(), '@var')) {
                $this->scopeManager->extractVarDocblock($doc->getText(), $node->valueVar);
            }
        }

        if ($node instanceof Node\Expr\Assign) {
            if ($node->var instanceof Node\Expr\Variable && \is_string($node->var->name)) {
                $varName = $node->var->name;
                $typeString = $this->scopeManager->getVarTypeFromScope($varName);

                if ($typeString !== null) {
                    $checkCall = NodeBuilder::createVariableCheckCall($node->expr, $typeString, $varName);
                    $node->expr = NodeBuilder::createTernaryThrowExpr($checkCall, $node->var->getStartLine());
                }
            } elseif ($node->var instanceof Node\Expr\PropertyFetch && $node->var->name instanceof Node\Identifier) {
                $propName = $node->var->name->toString();
                $objExpr = $node->var->var;

                $checkCall = NodeBuilder::createPropertyCheckCall($node->expr, $objExpr, $propName);
                $node->expr = NodeBuilder::createTernaryThrowExpr($checkCall, $node->var->getStartLine());
            } elseif ($node->var instanceof Node\Expr\StaticPropertyFetch && $node->var->name instanceof Node\VarLikeIdentifier) {
                $propName = $node->var->name->toString();
                $classExpr = $node->var->class;

                $classArg = $classExpr instanceof Node\Name
                    ? new Node\Expr\ClassConstFetch($classExpr, 'class')
                    : $classExpr;

                $checkCall = NodeBuilder::createPropertyCheckCall($node->expr, $classArg, $propName);
                $node->expr = NodeBuilder::createTernaryThrowExpr($checkCall, $node->var->getStartLine());
            }
        }

        return null;
    }

    /**
     * Pops the current lexical scope stack frame or replaces transformed expressions upon leaving a node.
     */
    public function leaveNode(Node $node): Node|null
    {
        if ($node instanceof Node\Expr\Clone_) {
            if ($node->getAttribute('typephp_wrapped') === true) {
                return null;
            }

            $node->setAttribute('typephp_wrapped', value: true);

            return new Node\Expr\FuncCall(
                new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::cloneInstance'),
                [
                    new Node\Arg(
                        new Node\Expr\Clone_(
                            new Node\Expr\FuncCall(
                                new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::prepareClone'),
                                [new Node\Arg($node->expr)]
                            )
                        )
                    ),
                    new Node\Arg($node->expr),
                ]
            );
        }

        if ($node instanceof Node\Stmt\Function_
            || $node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction
            || $node instanceof Node\Stmt\If_
            || $node instanceof Node\Stmt\Else_
            || $node instanceof Node\Stmt\ElseIf_
            || $node instanceof Node\Stmt\Foreach_
            || $node instanceof Node\Stmt\While_
            || $node instanceof Node\Stmt\For_
            || $node instanceof Node\Stmt\Do_
            || $node instanceof Node\Stmt\TryCatch
        ) {
            $this->scopeManager->popScope();
        }

        return null;
    }

    /**
     * Recursively extracts target variables assigned inside a list() or [] destructuring node.
     *
     * @return array<int, array{varName: string, expr: Node\Expr\Variable}>
     */
    private function extractDestructuringVariables(Node\Expr\List_|Node\Expr\Array_ $listNode): array
    {
        $vars = [];
        foreach ($listNode->items as $item) {
            if ($item === null) {
                continue;
            }

            if ($item->value instanceof Node\Expr\Variable && \is_string($item->value->name)) {
                $vars[] = [
                    'varName' => $item->value->name,
                    'expr' => $item->value,
                ];
            } elseif ($item->value instanceof Node\Expr\List_ || $item->value instanceof Node\Expr\Array_) {
                $vars = array_merge($vars, $this->extractDestructuringVariables($item->value));
            }
        }

        return $vars;
    }
}
