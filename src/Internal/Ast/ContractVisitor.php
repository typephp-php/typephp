<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use TypePHP\Internal\Docblock\DocblockExtractor;

/**
 * @internal AST Node Visitor that injects contract checks, scope tracking, property hook validation, and parameter/return wrappers into functions and methods.
 */
final class ContractVisitor extends NodeVisitorAbstract
{
    private ScopeManager $scopeManager;

    private string $currentNamespace = '';

    /**
     * @var list<array{name: ?string, isAnonymous: bool}>
     */
    private array $classStack = [];

    /**
     * @var list<array{name: string, isStatic: bool}>
     */
    private array $methodStack = [];

    /**
     * @var list<string>
     */
    private array $functionStack = [];

    /**
     * @var list<bool>
     */
    private array $thisAvailableStack = [];

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
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name !== null ? $node->name->toString() : '';
        } elseif ($node instanceof Node\Stmt\Class_) {
            $className = $node->name !== null
                ? ($this->currentNamespace !== '' ? $this->currentNamespace . '\\' . $node->name->toString() : $node->name->toString())
                : null;
            $hasExtends = $node->extends !== null;
            $hasImplements = ! empty($node->implements);
            $hasTraits = false;
            $hasPropertyWithDoc = false;

            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\TraitUse) {
                    $hasTraits = true;
                } elseif ($stmt instanceof Node\Stmt\Property && $stmt->getDocComment() !== null) {
                    $hasPropertyWithDoc = true;
                }
            }

            $doc = $node->getDocComment();
            $hasClassDoc = $doc !== null && (
                str_contains($doc->getText(), '@template')
                || str_contains($doc->getText(), '@phpstan-')
                || str_contains($doc->getText(), '@psalm-')
            );

            $this->classStack[] = [
                'name' => $className,
                'isAnonymous' => ($node->name === null),
                'hasInheritance' => ($hasExtends || $hasImplements || $hasTraits || $hasClassDoc),
                'hasPropertyWithDoc' => $hasPropertyWithDoc,
            ];
        } elseif ($node instanceof Node\Stmt\Interface_) {
            $typeName = $node->name !== null
                ? ($this->currentNamespace !== '' ? $this->currentNamespace . '\\' . $node->name->toString() : $node->name->toString())
                : null;
            $this->classStack[] = [
                'name' => $typeName,
                'isAnonymous' => false,
                'hasInheritance' => true,
            ];
        } elseif ($node instanceof Node\Stmt\Trait_) {
            $typeName = $node->name !== null
                ? ($this->currentNamespace !== '' ? $this->currentNamespace . '\\' . $node->name->toString() : $node->name->toString())
                : null;
            $hasTraits = false;
            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\TraitUse) {
                    $hasTraits = true;

                    break;
                }
            }
            $doc = $node->getDocComment();
            $hasClassDoc = $doc !== null && (
                str_contains($doc->getText(), '@template')
                || str_contains($doc->getText(), '@phpstan-')
                || str_contains($doc->getText(), '@psalm-')
            );
            $this->classStack[] = [
                'name' => $typeName,
                'isAnonymous' => false,
                'hasInheritance' => ($hasTraits || $hasClassDoc),
            ];
        } elseif ($node instanceof Node\Stmt\Enum_) {
            $typeName = $node->name !== null
                ? ($this->currentNamespace !== '' ? $this->currentNamespace . '\\' . $node->name->toString() : $node->name->toString())
                : null;
            $this->classStack[] = [
                'name' => $typeName,
                'isAnonymous' => false,
                'hasInheritance' => ! empty($node->implements),
            ];
        } elseif ($node instanceof Node\Stmt\ClassMethod) {
            $this->methodStack[] = ['name' => $node->name->toString(), 'isStatic' => $node->isStatic()];
            $this->thisAvailableStack[] = ! $node->isStatic();
        } elseif ($node instanceof Node\Stmt\Function_) {
            $funcName = $this->currentNamespace !== '' ? $this->currentNamespace . '\\' . $node->name->toString() : $node->name->toString();
            $this->functionStack[] = $funcName;
            $this->thisAvailableStack[] = false;
        } elseif ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $parentHasThis = ! empty($this->thisAvailableStack) && end($this->thisAvailableStack);
            $this->thisAvailableStack[] = $parentHasThis && ! $node->static;
        }

        if (
            $node instanceof Node\Stmt\Function_
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
                        $checkCall = NodeBuilder::createVariableCheckCall(
                            $node->expr,
                            $typeString,
                            $effectiveVarName,
                            $this->getCurrentCallerExpr(),
                            $this->getCurrentThisExpr()
                        );
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
                            $checkCall = NodeBuilder::createVariableCheckCall(
                                $dVar['expr'],
                                $typeString,
                                $varName,
                                $this->getCurrentCallerExpr(),
                                $this->getCurrentThisExpr()
                            );
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
                    $checkCall = NodeBuilder::createVariableCheckCall(
                        $node->expr,
                        $typeString,
                        $varName,
                        $this->getCurrentCallerExpr(),
                        $this->getCurrentThisExpr()
                    );
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
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = '';
        } elseif ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Interface_ || $node instanceof Node\Stmt\Trait_ || $node instanceof Node\Stmt\Enum_) {
            array_pop($this->classStack);
        } elseif ($node instanceof Node\Stmt\ClassMethod) {
            array_pop($this->methodStack);
            array_pop($this->thisAvailableStack);
        } elseif ($node instanceof Node\Stmt\Function_) {
            array_pop($this->functionStack);
            array_pop($this->thisAvailableStack);
        } elseif ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            array_pop($this->thisAvailableStack);
        }

        if ($node instanceof Node\Expr\Clone_) {
            if ($node->getAttribute('typephp_wrapped') === true) {
                return null;
            }

            $node->setAttribute('typephp_wrapped', value: true);

            return new Node\Expr\FuncCall(
                new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::cloneInstance'),
                [
                    new Node\Arg(
                        new Node\Expr\Clone_(
                            new Node\Expr\FuncCall(
                                new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::prepareClone'),
                                [new Node\Arg($node->expr)]
                            )
                        )
                    ),
                    new Node\Arg($node->expr),
                ]
            );
        }

        if (
            $node instanceof Node\Stmt\Function_
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

    private function getCurrentCallerExpr(): Node\Expr
    {
        if (! empty($this->methodStack) && ! empty($this->classStack)) {
            $classInfo = end($this->classStack);
            $methodInfo = end($this->methodStack);

            if (! $classInfo['isAnonymous'] && $classInfo['name'] !== null) {
                return new Node\Scalar\String_($classInfo['name'] . '::' . $methodInfo['name']);
            }

            return new Node\Expr\BinaryOp\Concat(
                new Node\Scalar\MagicConst\Class_(),
                new Node\Scalar\String_('::' . $methodInfo['name'])
            );
        }

        if (! empty($this->functionStack)) {
            return new Node\Scalar\String_(end($this->functionStack));
        }

        return new Node\Scalar\String_('');
    }

    private function getCurrentThisExpr(): Node\Expr
    {
        $hasThis = ! empty($this->thisAvailableStack) && end($this->thisAvailableStack);

        return $hasThis
            ? new Node\Expr\Variable('this')
            : new Node\Expr\ConstFetch(new Node\Name('null'));
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
