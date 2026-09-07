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
     * @var list<array{name: ?string, isAnonymous: bool, hasInheritance: bool, hasPropertyWithDoc: bool}>
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
        $this->trackDeclarationEntry($node);

        if ($this->isScopeBoundary($node)) {
            $this->scopeManager->pushScope();
        }

        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
            $classContext = $this->classStack !== [] ? end($this->classStack) : null;
            FunctionContractInjector::inject($node, $classContext);

            return null;
        }

        if ($node instanceof Node\Stmt\Property) {
            PropertyHookInjector::process($node);

            return null;
        }

        if ($node instanceof Node\Stmt\Return_ && $node->expr !== null) {
            $this->handleReturn($node);

            return null;
        }

        if ($node instanceof Node\Stmt\Expression) {
            return $this->handleExpression($node);
        }

        if ($node instanceof Node\Stmt\Foreach_) {
            $doc = $node->getDocComment();
            if ($doc !== null && str_contains($doc->getText(), '@var')) {
                $this->scopeManager->extractVarDocblock($doc->getText(), $node->valueVar);
            }

            return null;
        }

        if ($node instanceof Node\Expr\Assign) {
            $this->handleAssign($node);
        }

        return null;
    }

    /**
     * Pops the current lexical scope stack frame or replaces transformed expressions upon leaving a node.
     */
    public function leaveNode(Node $node): ?Node
    {
        $this->trackDeclarationExit($node);

        if ($node instanceof Node\Expr\Clone_) {
            return $this->wrapClone($node);
        }

        if ($this->isScopeBoundary($node)) {
            $this->scopeManager->popScope();
        }

        return null;
    }

    private function trackDeclarationEntry(Node $node): void
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name !== null ? $node->name->toString() : '';
        } elseif (
            $node instanceof Node\Stmt\Class_
            || $node instanceof Node\Stmt\Interface_
            || $node instanceof Node\Stmt\Trait_
            || $node instanceof Node\Stmt\Enum_
        ) {
            $this->enterClassLike($node);
        } elseif ($node instanceof Node\Stmt\ClassMethod) {
            $this->methodStack[] = ['name' => $node->name->toString(), 'isStatic' => $node->isStatic()];
            $this->thisAvailableStack[] = ! $node->isStatic();
        } elseif ($node instanceof Node\Stmt\Function_) {
            $this->functionStack[] = $this->resolveQualifiedName($node->name) ?? '';
            $this->thisAvailableStack[] = false;
        } elseif ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $this->thisAvailableStack[] = ! $node->static;
        }
    }

    private function trackDeclarationExit(Node $node): void
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = '';
        } elseif (
            $node instanceof Node\Stmt\Class_
            || $node instanceof Node\Stmt\Interface_
            || $node instanceof Node\Stmt\Trait_
            || $node instanceof Node\Stmt\Enum_
        ) {
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
    }

    private function enterClassLike(Node\Stmt\Class_|Node\Stmt\Interface_|Node\Stmt\Trait_|Node\Stmt\Enum_ $node): void
    {
        $typeName = $this->resolveQualifiedName($node->name);
        $hasInheritance = true;
        $hasPropertyWithDoc = false;

        if ($node instanceof Node\Stmt\Class_) {
            $hasExtends = $node->extends !== null;
            $hasImplements = $node->implements !== [];
            $hasTraits = false;

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

            $hasInheritance = $hasExtends || $hasImplements || $hasTraits || $hasClassDoc;
        } elseif ($node instanceof Node\Stmt\Enum_) {
            $hasInheritance = $node->implements !== [];
        }

        $this->classStack[] = [
            'name' => $typeName,
            'isAnonymous' => ($node instanceof Node\Stmt\Class_ && $node->name === null),
            'hasInheritance' => $hasInheritance,
            'hasPropertyWithDoc' => $hasPropertyWithDoc,
        ];
    }

    private function handleReturn(Node\Stmt\Return_ $node): void
    {
        if ($node->expr === null) {
            return;
        }

        $doc = $node->getDocComment();
        if ($doc === null || ! str_contains($doc->getText(), '@var')) {
            return;
        }

        $extracted = DocblockExtractor::extractVarTagFromDoc($doc->getText());
        if ($extracted === null) {
            return;
        }

        [$typeString, $varName] = $extracted;
        $isApplicable = ($varName === '')
            || ($node->expr instanceof Node\Expr\Variable && $node->expr->name === $varName);

        if ($isApplicable) {
            $node->expr = $this->wrapVariableCheck(
                $node->expr,
                $typeString,
                $varName !== '' ? $varName : 'return',
                $node->getStartLine()
            );
            $node->setAttribute('typephp_var_wrapped', true);
        }
    }

    /**
     * @return array<Node>|null
     */
    private function handleExpression(Node\Stmt\Expression $node): ?array
    {
        $doc = $node->getDocComment();
        if ($doc !== null && str_contains($doc->getText(), '@var')) {
            $this->scopeManager->extractVarDocblock($doc->getText(), $node->expr);
        }

        if (! ($node->expr instanceof Node\Expr\Assign) || ! $this->isDestructuring($node->expr->var)) {
            return null;
        }

        /** @var Node\Expr\List_|Node\Expr\Array_ $destructuringVar */
        $destructuringVar = $node->expr->var;
        $destructuredVars = $this->extractDestructuringVariables($destructuringVar);
        $checkStmts = [];

        foreach ($destructuredVars as $dVar) {
            $varName = $dVar['varName'];
            $typeString = $this->scopeManager->getVarTypeFromScope($varName);

            if ($typeString !== null) {
                $checkStmt = new Node\Stmt\Expression(
                    new Node\Expr\Assign(
                        $dVar['expr'],
                        $this->wrapVariableCheck($dVar['expr'], $typeString, $varName, $dVar['expr']->getStartLine())
                    )
                );
                $checkStmt->setAttribute('typephp_injected', true);
                $checkStmts[] = $checkStmt;
            }
        }

        return $checkStmts !== [] ? array_merge([$node], $checkStmts) : null;
    }

    private function handleAssign(Node\Expr\Assign $node): void
    {
        if ($node->var instanceof Node\Expr\Variable && \is_string($node->var->name)) {
            $varName = $node->var->name;
            $typeString = $this->scopeManager->getVarTypeFromScope($varName);

            if ($typeString !== null) {
                $node->expr = $this->wrapVariableCheck($node->expr, $typeString, $varName, $node->var->getStartLine());
            }
        } elseif ($node->var instanceof Node\Expr\PropertyFetch && $node->var->name instanceof Node\Identifier) {
            $node->expr = $this->wrapPropertyCheck($node->expr, $node->var->var, $node->var->name->toString(), $node->var->getStartLine());
        } elseif ($node->var instanceof Node\Expr\StaticPropertyFetch && $node->var->name instanceof Node\VarLikeIdentifier) {
            $classArg = $node->var->class instanceof Node\Name
                ? new Node\Expr\ClassConstFetch($node->var->class, 'class')
                : $node->var->class;

            $node->expr = $this->wrapPropertyCheck($node->expr, $classArg, $node->var->name->toString(), $node->var->getStartLine());
        }
    }

    private function wrapVariableCheck(Node\Expr $expr, string $typeString, string $varName, int $line): Node\Expr\Ternary
    {
        $checkCall = NodeBuilder::createVariableCheckCall(
            $expr,
            $typeString,
            $varName,
            $this->getCurrentCallerExpr(),
            $this->getCurrentThisExpr()
        );

        return NodeBuilder::createTernaryThrowExpr($checkCall, $line);
    }

    private function wrapPropertyCheck(Node\Expr $valueExpr, Node\Expr $targetExpr, string $propName, int $line): Node\Expr\Ternary
    {
        $checkCall = NodeBuilder::createPropertyCheckCall($valueExpr, $targetExpr, $propName);

        return NodeBuilder::createTernaryThrowExpr($checkCall, $line);
    }

    private function wrapClone(Node\Expr\Clone_ $node): ?Node\Expr\FuncCall
    {
        if ($node->getAttribute('typephp_wrapped') === true) {
            return null;
        }

        $node->setAttribute('typephp_wrapped', true);

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

    private function isScopeBoundary(Node $node): bool
    {
        return $node instanceof Node\Stmt\Function_
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
            || $node instanceof Node\Stmt\TryCatch;
    }

    private function isDestructuring(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\List_
            || ($expr instanceof Node\Expr\Array_ && $expr->getAttribute('kind') === Node\Expr\Array_::KIND_SHORT);
    }

    private function resolveQualifiedName(?Node\Identifier $name): ?string
    {
        if ($name === null) {
            return null;
        }

        return $this->currentNamespace !== ''
            ? $this->currentNamespace . '\\' . $name->toString()
            : $name->toString();
    }

    private function getCurrentCallerExpr(): Node\Expr
    {
        if ($this->methodStack !== [] && $this->classStack !== []) {
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

        if ($this->functionStack !== []) {
            return new Node\Scalar\String_(end($this->functionStack));
        }

        return new Node\Scalar\String_('');
    }

    private function getCurrentThisExpr(): Node\Expr
    {
        $hasThis = $this->classStack !== []
            && $this->thisAvailableStack !== []
            && end($this->thisAvailableStack);

        return $hasThis
            ? new Node\Expr\Variable('this')
            : new Node\Expr\ConstFetch(new Node\Name('null'));
    }

    /**
     * Recursively extracts target variables assigned inside a list() or [] destructuring node.
     *
     * @return list<array{varName: string, expr: Node\Expr\Variable}>
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
