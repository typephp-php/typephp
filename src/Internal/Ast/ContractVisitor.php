<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use TypePHP\Internal\Docblock\DocblockExtractor;
use TypePHP\Internal\Util\Config;

/**
 * @internal AST Node Visitor that injects contract checks, scope tracking, property hook validation, and parameter/return wrappers into functions and methods.
 */
final class ContractVisitor extends NodeVisitorAbstract
{
    private const ASSIGN_OP_MAP = [
        Node\Expr\AssignOp\Plus::class => Node\Expr\BinaryOp\Plus::class,
        Node\Expr\AssignOp\Minus::class => Node\Expr\BinaryOp\Minus::class,
        Node\Expr\AssignOp\Mul::class => Node\Expr\BinaryOp\Mul::class,
        Node\Expr\AssignOp\Div::class => Node\Expr\BinaryOp\Div::class,
        Node\Expr\AssignOp\Mod::class => Node\Expr\BinaryOp\Mod::class,
        Node\Expr\AssignOp\Concat::class => Node\Expr\BinaryOp\Concat::class,
        Node\Expr\AssignOp\Pow::class => Node\Expr\BinaryOp\Pow::class,
        Node\Expr\AssignOp\BitwiseAnd::class => Node\Expr\BinaryOp\BitwiseAnd::class,
        Node\Expr\AssignOp\BitwiseOr::class => Node\Expr\BinaryOp\BitwiseOr::class,
        Node\Expr\AssignOp\BitwiseXor::class => Node\Expr\BinaryOp\BitwiseXor::class,
        Node\Expr\AssignOp\ShiftLeft::class => Node\Expr\BinaryOp\ShiftLeft::class,
        Node\Expr\AssignOp\ShiftRight::class => Node\Expr\BinaryOp\ShiftRight::class,
        Node\Expr\AssignOp\Coalesce::class => Node\Expr\BinaryOp\Coalesce::class,
    ];

    private ScopeManager $scopeManager;

    private string $currentNamespace = '';

    /**
     * @var list<array{name: ?string, isAnonymous: bool, hasInheritance: bool, hasPropertyWithDoc: bool, isReadonly: bool, hasTemplates: bool}>
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

        if ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp) {
            $this->markWriteContext($node->var);
        } elseif ($node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostDec) {
            $this->markWriteContext($node->var);
        } elseif ($node instanceof Node\Stmt\Unset_) {
            foreach ($node->vars as $v) {
                $this->markWriteContext($v);
            }
        }

        if ($node instanceof Node\Stmt\Class_) {
            $this->processClassPropertyDefaults($node);
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

        if ($node instanceof Node\Stmt\Return_) {
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

        if ($node instanceof Node\Expr\AssignOp) {
            $replacement = $this->handleAssignOp($node);
            if ($replacement !== null) {
                return $replacement;
            }
        }

        if ($node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostDec) {
            $replacement = $this->handleIncDec($node);
            if ($replacement !== null) {
                return $replacement;
            }
        }

        if ($node instanceof Node\Expr\StaticPropertyFetch && $node->name instanceof Node\VarLikeIdentifier) {
            $propName = $node->name->toString();

            if ($node->getAttribute('typephp_checked') !== true && $node->getAttribute('typephp_write_context') !== true) {
                $node->setAttribute('typephp_checked', true);
                $classArg = $node->class instanceof Node\Name
                    ? new Node\Expr\ClassConstFetch($node->class, 'class')
                    : $node->class;

                return new Node\Expr\StaticCall(
                    new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker'),
                    'checkStaticProperty',
                    [
                        new Node\Arg($classArg),
                        new Node\Arg(new Node\Scalar\String_($propName)),
                        new Node\Arg($node),
                        new Node\Arg(new Node\Scalar\MagicConst\File()),
                        new Node\Arg(new Node\Scalar\LNumber($node->getStartLine())),
                    ]
                );
            }
        }

        if ($this->isScopeBoundary($node)) {
            $this->scopeManager->popScope();
        }

        return null;
    }

    private function markWriteContext(Node $node): void
    {
        $node->setAttribute('typephp_write_context', true);
        if ($node instanceof Node\Expr\ArrayDimFetch || $node instanceof Node\Expr\PropertyFetch) {
            $this->markWriteContext($node->var);
        }
    }

    private function processClassPropertyDefaults(Node\Stmt\Class_ $node): void
    {
        if (! Config::isInlinePropertiesEnabled()) {
            return;
        }

        $defaultProps = [];
        $hasConstructor = false;

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod && strtolower($stmt->name->toString()) === '__construct') {
                $hasConstructor = true;
            } elseif ($stmt instanceof Node\Stmt\Property && ! $stmt->isStatic()) {
                $doc = $stmt->getDocComment();
                if ($doc !== null && str_contains($doc->getText(), '@var') && ! str_contains($doc->getText(), '@typephp-ignore')) {
                    foreach ($stmt->props as $p) {
                        $isExplicitNull = $p->default instanceof Node\Expr\ConstFetch && strtolower($p->default->name->toString()) === 'null';
                        if ($p->default !== null && ! $isExplicitNull) {
                            $defaultProps[] = [
                                'name' => $p->name->toString(),
                                'line' => $p->getStartLine(),
                            ];
                        }
                    }
                }
            }
        }

        if ($defaultProps === []) {
            return;
        }

        if (! $hasConstructor) {
            $ctorStmts = [];
            if ($node->extends !== null) {
                $ctorStmts[] = new Node\Stmt\If_(
                    new Node\Expr\FuncCall(new Node\Name('method_exists'), [
                        new Node\Arg(new Node\Expr\ClassConstFetch(new Node\Name('parent'), 'class')),
                        new Node\Arg(new Node\Scalar\String_('__construct')),
                    ]),
                    ['stmts' => [
                        new Node\Stmt\Expression(new Node\Expr\StaticCall(new Node\Name('parent'), '__construct', [new Node\Arg(new Node\Expr\Variable('_typephp_ctor_args'), false, true)])),
                    ]]
                );
            }

            foreach ($defaultProps as $dp) {
                $checkCall = NodeBuilder::createPropertyCheckCall(
                    new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $dp['name']),
                    new Node\Expr\Variable('this'),
                    $dp['name']
                );
                $stmt = new Node\Stmt\Expression(NodeBuilder::createTernaryThrowExpr($checkCall, $dp['line']));
                $stmt->setAttribute('typephp_injected', true);
                $ctorStmts[] = $stmt;
            }

            $ctor = new Node\Stmt\ClassMethod('__construct', [
                'flags' => Node\Stmt\Class_::MODIFIER_PUBLIC,
                'params' => [new Node\Param(new Node\Expr\Variable('_typephp_ctor_args'), null, null, false, true)],
                'stmts' => $ctorStmts,
            ]);
            $ctor->setAttribute('typephp_injected', true);
            $node->stmts[] = $ctor;
        } else {
            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\ClassMethod && strtolower($stmt->name->toString()) === '__construct') {
                    $injected = [];
                    foreach ($defaultProps as $dp) {
                        $checkCall = NodeBuilder::createPropertyCheckCall(
                            new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $dp['name']),
                            new Node\Expr\Variable('this'),
                            $dp['name']
                        );
                        $checkStmt = new Node\Stmt\Expression(NodeBuilder::createTernaryThrowExpr($checkCall, $dp['line']));
                        $checkStmt->setAttribute('typephp_injected', true);
                        $injected[] = $checkStmt;
                    }
                    $stmt->stmts = [...$injected, ...($stmt->stmts ?? [])];

                    break;
                }
            }
        }
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
        $isReadonly = false;
        $hasTemplates = false;

        if ($node instanceof Node\Stmt\Class_) {
            $hasExtends = $node->extends !== null;
            $hasImplements = $node->implements !== [];
            $hasTraits = false;
            $isReadonly = ($node->flags & Node\Stmt\Class_::MODIFIER_READONLY) !== 0;

            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\TraitUse) {
                    $hasTraits = true;
                } elseif ($stmt instanceof Node\Stmt\Property && $stmt->getDocComment() !== null) {
                    $hasPropertyWithDoc = true;
                }
            }

            $doc = $node->getDocComment();
            $hasTemplates = $doc !== null && (
                str_contains($doc->getText(), '@template')
                || str_contains($doc->getText(), '@phpstan-template')
                || str_contains($doc->getText(), '@psalm-template')
            );
            $hasClassDoc = $doc !== null && (
                $hasTemplates
                || str_contains($doc->getText(), '@phpstan-')
                || str_contains($doc->getText(), '@psalm-')
            );

            $hasInheritance = $hasExtends || $hasImplements || $hasTraits || $hasClassDoc;
        } elseif ($node instanceof Node\Stmt\Enum_) {
            $hasInheritance = $node->implements !== [];
        } else {
            $doc = $node->getDocComment();
            $hasTemplates = $doc !== null && (
                str_contains($doc->getText(), '@template')
                || str_contains($doc->getText(), '@phpstan-template')
                || str_contains($doc->getText(), '@psalm-template')
            );
        }

        $this->classStack[] = [
            'name' => $typeName,
            'isAnonymous' => ($node instanceof Node\Stmt\Class_ && $node->name === null),
            'hasInheritance' => $hasInheritance,
            'hasPropertyWithDoc' => $hasPropertyWithDoc,
            'isReadonly' => $isReadonly,
            'hasTemplates' => $hasTemplates,
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

        return $checkStmts !== [] ? [$node, ...$checkStmts] : null;
    }

    private function handleAssign(Node\Expr\Assign $node): void
    {
        if ($node->var instanceof Node\Expr\Variable && \is_string($node->var->name)) {
            $varName = $node->var->name;
            $typeString = $this->scopeManager->getVarTypeFromScope($varName);

            if ($typeString !== null) {
                $expr = $node->expr;
                if ($expr instanceof Node\Expr\New_ && str_contains($typeString, '<')) {
                    $expr = new Node\Expr\StaticCall(
                        new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker'),
                        'withPendingGeneric',
                        [
                            new Node\Arg(new Node\Scalar\String_($typeString)),
                            new Node\Arg(new Node\Expr\ArrowFunction([
                                'expr' => $expr,
                            ])),
                            new Node\Arg(new Node\Scalar\MagicConst\File()),
                        ]
                    );
                }

                $node->expr = $this->wrapVariableCheck($expr, $typeString, $varName, $node->var->getStartLine());
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

    private function handleAssignOp(Node\Expr\AssignOp $node): ?Node\Expr\Assign
    {
        $binaryOpClass = self::ASSIGN_OP_MAP[$node::class] ?? null;
        if ($binaryOpClass === null) {
            return null;
        }

        $binaryExpr = new $binaryOpClass($node->var, $node->expr);

        if ($node->var instanceof Node\Expr\Variable && \is_string($node->var->name)) {
            $varName = $node->var->name;
            $typeString = $this->scopeManager->getVarTypeFromScope($varName);

            if ($typeString !== null) {
                return new Node\Expr\Assign(
                    $node->var,
                    $this->wrapVariableCheck($binaryExpr, $typeString, $varName, $node->var->getStartLine())
                );
            }
        } elseif ($node->var instanceof Node\Expr\PropertyFetch && $node->var->name instanceof Node\Identifier) {
            return new Node\Expr\Assign(
                $node->var,
                $this->wrapPropertyCheck($binaryExpr, $node->var->var, $node->var->name->toString(), $node->var->getStartLine())
            );
        } elseif ($node->var instanceof Node\Expr\StaticPropertyFetch && $node->var->name instanceof Node\VarLikeIdentifier) {
            $classArg = $node->var->class instanceof Node\Name
                ? new Node\Expr\ClassConstFetch($node->var->class, 'class')
                : $node->var->class;

            return new Node\Expr\Assign(
                $node->var,
                $this->wrapPropertyCheck($binaryExpr, $classArg, $node->var->name->toString(), $node->var->getStartLine())
            );
        }

        return null;
    }

    private function handleIncDec(Node\Expr\PreInc|Node\Expr\PostInc|Node\Expr\PreDec|Node\Expr\PostDec $node): ?Node\Expr\Assign
    {
        $isInc = $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostInc;
        $binaryClass = $isInc ? Node\Expr\BinaryOp\Plus::class : Node\Expr\BinaryOp\Minus::class;
        $binaryExpr = new $binaryClass($node->var, new Node\Scalar\LNumber(1));

        if ($node->var instanceof Node\Expr\Variable && \is_string($node->var->name)) {
            $varName = $node->var->name;
            $typeString = $this->scopeManager->getVarTypeFromScope($varName);

            if ($typeString !== null) {
                return new Node\Expr\Assign(
                    $node->var,
                    $this->wrapVariableCheck($binaryExpr, $typeString, $varName, $node->var->getStartLine())
                );
            }
        } elseif ($node->var instanceof Node\Expr\PropertyFetch && $node->var->name instanceof Node\Identifier) {
            return new Node\Expr\Assign(
                $node->var,
                $this->wrapPropertyCheck($binaryExpr, $node->var->var, $node->var->name->toString(), $node->var->getStartLine())
            );
        } elseif ($node->var instanceof Node\Expr\StaticPropertyFetch && $node->var->name instanceof Node\VarLikeIdentifier) {
            $classArg = $node->var->class instanceof Node\Name
                ? new Node\Expr\ClassConstFetch($node->var->class, 'class')
                : $node->var->class;

            return new Node\Expr\Assign(
                $node->var,
                $this->wrapPropertyCheck($binaryExpr, $classArg, $node->var->name->toString(), $node->var->getStartLine())
            );
        }

        return null;
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
                $vars = [...$vars, ...$this->extractDestructuringVariables($item->value)];
            }
        }

        return $vars;
    }
}
