<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * @internal Injects parameter checks, return checks, and generator interceptors into functions and methods.
 */
final class FunctionContractInjector
{
    /**
     * @param array{hasInheritance?: bool, hasPropertyWithDoc?: bool}|null $classContext
     */
    public static function inject(Node\Stmt\Function_|Node\Stmt\ClassMethod $node, ?array $classContext = null): void
    {
        if ($node->stmts === null) {
            return;
        }

        $isClassMethod = $node instanceof Node\Stmt\ClassMethod;
        $doc = $node->getDocComment();
        if ($doc === null) {
            foreach ($node->getComments() as $comment) {
                if ($comment instanceof \PhpParser\Comment\Doc) {
                    $doc = $comment;

                    break;
                }
            }
        }
        if ($doc === null && ! empty($node->attrGroups)) {
            foreach ($node->attrGroups as $group) {
                $groupDoc = $group->getDocComment();
                if ($groupDoc !== null) {
                    $doc = $groupDoc;

                    break;
                }
                foreach ($group->getComments() as $comment) {
                    if ($comment instanceof \PhpParser\Comment\Doc) {
                        $doc = $comment;

                        break 2;
                    }
                }
            }
        }

        $docText = $doc !== null ? $doc->getText() : '';

        $hasInheritance = $classContext['hasInheritance'] ?? true;
        $hasPropertyWithDoc = $classContext['hasPropertyWithDoc'] ?? true;

        $methodName = $isClassMethod ? strtolower($node->name->toString()) : '';
        $isConstructor = $isClassMethod && $methodName === '__construct';
        $isMagicLifecycle = $isClassMethod && \in_array($methodName, ['__construct', '__destruct', '__clone'], true);

        $isNativeNever = $node->returnType instanceof Node\Identifier && strtolower($node->returnType->name) === 'never';
        $isNativeVoid = $node->returnType instanceof Node\Identifier && strtolower($node->returnType->name) === 'void';
        $isPrivate = $isClassMethod && $node->isPrivate();

        $paramCount = \count($node->params);
        $hasParam = self::hasParamContracts($docText, $isClassMethod, $hasInheritance, $paramCount, $isPrivate, $isConstructor, $hasPropertyWithDoc, $classContext === null, ! empty($node->attrGroups));

        $hasReturnDoc = str_contains($docText, '@return') || str_contains($docText, '@phpstan-return') || str_contains($docText, '@psalm-return');
        $hasReturn = ! $isMagicLifecycle
            && ! $isNativeNever
            && ! ($isNativeVoid && ! $hasReturnDoc)
            && self::hasReturnContracts($docText, $isClassMethod, $hasInheritance, $isPrivate);

        if (! $hasParam && ! $hasReturn) {
            return;
        }

        $thisArg = self::resolveThisArg($isClassMethod, $node);
        $needsReturnVars = $hasParam && ($paramCount > 0) && (
            $hasInheritance || str_contains($docText, ' is ') || ($hasReturnDoc && str_contains($docText, '$'))
        );

        $injectedStmts = [];
        if ($hasParam) {
            $injectedStmts = self::buildParamInjections($node->params, $docText, $thisArg);
        }

        if ($hasReturn) {
            $node->stmts = self::isGenerator($node)
                ? self::wrapGeneratorReturns($node->stmts, $thisArg)
                : self::wrapNonGeneratorReturns($node->stmts, $thisArg, $isNativeVoid, $needsReturnVars);
        }

        $node->stmts = [...$injectedStmts, ...$node->stmts];
    }

    private static function hasParamContracts(
        string $docText,
        bool $isClassMethod,
        bool $hasInheritance,
        int $paramCount,
        bool $isPrivate,
        bool $isConstructor,
        bool $hasPropertyWithDoc,
        bool $isDirectUnitTest,
        bool $hasAttributes = false
    ): bool {
        if ($paramCount === 0 && ! str_contains($docText, '@template')) {
            if ($isDirectUnitTest && $isClassMethod) {
                return true;
            }

            return false;
        }

        if ($isDirectUnitTest && $isClassMethod) {
            return true;
        }

        if ($isConstructor && $docText === '' && ! $hasPropertyWithDoc) {
            return false;
        }

        if ($isPrivate && $docText === '') {
            return false;
        }

        if (! $hasInheritance && $docText === '' && ! ($isConstructor && $hasPropertyWithDoc) && ! $hasAttributes) {
            return false;
        }

        if (str_contains($docText, '@template') || str_contains($docText, '@phpstan-param') || str_contains($docText, '@psalm-param')) {
            return true;
        }

        if (str_contains($docText, '@param')) {
            if ((int) preg_match_all('/@param\s+([^\s$]+)/', $docText, $matches) > 0) {
                foreach ($matches[1] as $typeStr) {
                    $unionParts = explode('|', $typeStr);
                    $hasMixed = false;
                    foreach ($unionParts as $part) {
                        if (strtolower(trim($part)) === 'mixed') {
                            $hasMixed = true;

                            break;
                        }
                    }

                    if (! $hasMixed) {
                        return true;
                    }
                }
            }

            return false;
        }

        return $isClassMethod && ! $isPrivate;
    }

    private static function hasReturnContracts(
        string $docText,
        bool $isClassMethod,
        bool $hasInheritance,
        bool $isPrivate
    ): bool {
        if ($isPrivate && $docText === '') {
            return false;
        }

        if (
            str_contains($docText, '@template')
            || str_contains($docText, '@phpstan-return')
            || str_contains($docText, '@psalm-return')
            || str_contains($docText, '$this')
        ) {
            return true;
        }

        if (preg_match('/@return\s+([^\s]+)/', $docText, $matches) === 1) {
            $returnTypeStr = $matches[1];
            $unionParts = explode('|', $returnTypeStr);
            foreach ($unionParts as $part) {
                if (strtolower(trim($part)) === 'mixed') {
                    return false;
                }
            }

            return true;
        }

        return $isClassMethod && ! $isPrivate;
    }

    private static function resolveThisArg(bool $isClassMethod, Node\Stmt\Function_|Node\Stmt\ClassMethod $node): Node\Expr
    {
        if (! $isClassMethod) {
            return new Node\Expr\ConstFetch(new Node\Name('null'));
        }

        /** @var Node\Stmt\ClassMethod $node */
        return $node->isStatic()
            ? new Node\Expr\ClassConstFetch(new Node\Name('static'), 'class')
            : new Node\Expr\Variable('this');
    }

    private static function isGenerator(Node\Stmt\Function_|Node\Stmt\ClassMethod $node): bool
    {
        if ($node->stmts === null) {
            return false;
        }

        $visitor = new class () extends NodeVisitorAbstract {
            public bool $isGen = false;

            public function enterNode(Node $n): ?int
            {
                if ($n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction || $n instanceof Node\Stmt\Function_ || $n instanceof Node\Stmt\ClassMethod) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                if ($n instanceof Node\Expr\Yield_ || $n instanceof Node\Expr\YieldFrom) {
                    $this->isGen = true;

                    return NodeTraverser::STOP_TRAVERSAL;
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($node->stmts);

        return $visitor->isGen;
    }

    /**
     * @param array<Node\Param> $params
     *
     * @return array<Node\Stmt>
     */
    private static function buildParamInjections(
        array $params,
        string $docText,
        Node\Expr $thisArg
    ): array {
        $injectedStmts = [self::buildSetupScopeStmt($params, $thisArg)];

        $callableWrappers = self::buildCallableParamWrappers($params, $docText, $thisArg);
        $iterableWrappers = self::buildIterableParamWrappers($params, $docText, $thisArg);

        return [...$injectedStmts, ...$callableWrappers, ...$iterableWrappers];
    }

    /**
     * @param array<Node\Param> $params
     */
    private static function buildSetupScopeStmt(array $params, Node\Expr $thisArg): Node\Stmt\If_
    {
        $arrayItems = [];
        foreach ($params as $param) {
            if ($param->var instanceof Node\Expr\Variable && \is_string($param->var->name)) {
                $pName = $param->var->name;
                $arrayItems[] = new Node\ArrayItem(
                    new Node\Expr\Variable($pName),
                    new Node\Scalar\String_($pName)
                );
            }
        }

        $argsExpr = new Node\Expr\Assign(
            new Node\Expr\Variable('__typephpArgs'),
            new Node\Expr\Array_($arrayItems)
        );

        $checkCall = new Node\Expr\FuncCall(
            new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::setupScope'),
            [
                new Node\Arg(new Node\Scalar\MagicConst\Method()),
                new Node\Arg($argsExpr),
                new Node\Arg($thisArg),
            ]
        );

        $throwStmt = self::buildTypeErrorThrowStmt(new Node\Expr\Variable('__typephpErr'));

        $ifStmt = new Node\Stmt\If_(
            new Node\Expr\Instanceof_(
                new Node\Expr\Assign(new Node\Expr\Variable('__typephpErr'), $checkCall),
                new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorMessage')
            ),
            ['stmts' => [$throwStmt]]
        );

        $ifStmt->setAttribute('typephp_injected', true);

        return $ifStmt;
    }

    /**
     * @param array<Node\Param> $params
     *
     * @return array<Node\Stmt>
     */
    private static function buildCallableParamWrappers(array $params, string $docText, Node\Expr $thisArg): array
    {
        $wrappers = [];
        foreach ($params as $param) {
            if (self::isCallableCandidate($param, $docText) && $param->var instanceof Node\Expr\Variable && \is_string($param->var->name)) {
                $paramName = $param->var->name;
                $expr = new Node\Stmt\Expression(
                    new Node\Expr\Assign(
                        new Node\Expr\Variable($paramName),
                        new Node\Expr\FuncCall(
                            new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::wrapCallable'),
                            [
                                new Node\Arg(new Node\Scalar\MagicConst\Method()),
                                new Node\Arg(new Node\Scalar\String_($paramName)),
                                new Node\Arg(new Node\Expr\Variable($paramName)),
                                new Node\Arg($thisArg),
                            ]
                        )
                    )
                );
                $expr->setAttribute('typephp_injected', true);
                $wrappers[] = $expr;
            }
        }

        return $wrappers;
    }

    /**
     * @param array<Node\Param> $params
     *
     * @return array<Node\Stmt>
     */
    private static function buildIterableParamWrappers(array $params, string $docText, Node\Expr $thisArg): array
    {
        $wrappers = [];
        foreach ($params as $param) {
            if (self::isIterableCandidate($param, $docText) && $param->var instanceof Node\Expr\Variable && \is_string($param->var->name)) {
                $paramName = $param->var->name;
                $expr = new Node\Stmt\Expression(
                    new Node\Expr\Assign(
                        new Node\Expr\Variable($paramName),
                        new Node\Expr\FuncCall(
                            new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::wrapIterable'),
                            [
                                new Node\Arg(new Node\Scalar\MagicConst\Method()),
                                new Node\Arg(new Node\Scalar\String_($paramName)),
                                new Node\Arg(new Node\Expr\Variable($paramName)),
                                new Node\Arg($thisArg),
                            ]
                        )
                    )
                );
                $expr->setAttribute('typephp_injected', true);
                $wrappers[] = $expr;
            }
        }

        return $wrappers;
    }

    private static function isCallableCandidate(Node\Param $param, string $docText): bool
    {
        if (
            str_contains($docText, 'callable')
            || str_contains($docText, 'Closure')
            || str_contains($docText, 'pure-callable')
            || str_contains($docText, 'static-closure')
        ) {
            return true;
        }

        if ($param->type instanceof Node\Identifier) {
            return strtolower($param->type->name) === 'callable';
        }

        if ($param->type instanceof Node\Name) {
            return strtolower($param->type->getLast()) === 'closure';
        }

        if ($param->type instanceof Node\UnionType || $param->type instanceof Node\IntersectionType) {
            foreach ($param->type->types as $t) {
                if ($t instanceof Node\Identifier && strtolower($t->name) === 'callable') {
                    return true;
                }
                if ($t instanceof Node\Name && strtolower($t->getLast()) === 'closure') {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isIterableCandidate(Node\Param $param, string $docText): bool
    {
        if (
            str_contains($docText, 'iterable')
            || str_contains($docText, 'Traversable')
            || str_contains($docText, 'Generator')
            || str_contains($docText, 'Iterator')
            || str_contains($docText, 'IteratorAggregate')
        ) {
            return true;
        }

        $iterableTypes = [
            'iterable' => true,
            'traversable' => true,
            'generator' => true,
            'iterator' => true,
            'iteratoraggregate' => true,
        ];

        if ($param->type instanceof Node\Identifier) {
            return isset($iterableTypes[strtolower($param->type->name)]);
        }

        if ($param->type instanceof Node\Name) {
            return isset($iterableTypes[strtolower($param->type->getLast())]);
        }

        if ($param->type instanceof Node\UnionType || $param->type instanceof Node\IntersectionType) {
            foreach ($param->type->types as $t) {
                if ($t instanceof Node\Identifier && isset($iterableTypes[strtolower($t->name)])) {
                    return true;
                }
                if ($t instanceof Node\Name && isset($iterableTypes[strtolower($t->getLast())])) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function buildTypeErrorThrowStmt(Node\Expr $errorVar): Node\Stmt\Expression
    {
        return new Node\Stmt\Expression(
            new Node\Expr\Throw_(
                new Node\Expr\StaticCall(
                    new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorFactory'),
                    'prepareException',
                    [
                        new Node\Arg(
                            new Node\Expr\New_(
                                new Node\Name\FullyQualified('TypePHP\Exception\TypeError'),
                                [
                                    new Node\Arg(
                                        new Node\Expr\MethodCall($errorVar, 'getMessage')
                                    ),
                                ]
                            )
                        ),
                    ]
                )
            )
        );
    }

    public static function buildReturnCheckCall(Node\Expr $exprToWrap, Node\Expr $thisArg, bool $needsReturnVars = false): Node\Expr\FuncCall
    {
        $varsArg = $needsReturnVars
            ? new Node\Expr\Variable('__typephpArgs')
            : new Node\Expr\Array_();

        return new Node\Expr\FuncCall(
            new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::checkReturn'),
            [
                new Node\Arg(new Node\Scalar\MagicConst\Method()),
                new Node\Arg($exprToWrap),
                new Node\Arg($thisArg),
                new Node\Arg($varsArg),
            ]
        );
    }

    /**
     * @return array<Node\Stmt>
     */
    public static function buildVoidReturnGuard(Node\Expr\FuncCall $checkCall): array
    {
        $ifStmt = new Node\Stmt\If_(
            new Node\Expr\Instanceof_(
                new Node\Expr\Assign(new Node\Expr\Variable('__typephpRet'), $checkCall),
                new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorMessage')
            ),
            ['stmts' => [self::buildTypeErrorThrowStmt(new Node\Expr\Variable('__typephpRet'))]]
        );
        $ifStmt->setAttribute('typephp_injected', true);

        $retStmt = new Node\Stmt\Return_(null);
        $retStmt->setAttribute('typephp_injected', true);

        return [$ifStmt, $retStmt];
    }

    public static function buildTernaryReturnExpr(Node\Expr\FuncCall $checkCall): Node\Expr\Ternary
    {
        return new Node\Expr\Ternary(
            new Node\Expr\Instanceof_(
                new Node\Expr\Assign(new Node\Expr\Variable('__typephpRet'), $checkCall),
                new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorMessage')
            ),
            new Node\Expr\Throw_(
                new Node\Expr\StaticCall(
                    new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorFactory'),
                    'prepareException',
                    [
                        new Node\Arg(
                            new Node\Expr\New_(
                                new Node\Name\FullyQualified('TypePHP\Exception\TypeError'),
                                [
                                    new Node\Arg(
                                        new Node\Expr\MethodCall(new Node\Expr\Variable('__typephpRet'), 'getMessage')
                                    ),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new Node\Expr\Variable('__typephpRet')
        );
    }

    public static function buildWrappedYieldNode(Node\Expr\Yield_ $n, Node\Expr $thisArg): Node\Expr\Ternary
    {
        $checkYieldCall = new Node\Expr\FuncCall(
            new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::checkYield'),
            [
                new Node\Arg(new Node\Scalar\MagicConst\Method()),
                new Node\Arg($n->key ?? new Node\Expr\ConstFetch(new Node\Name('null'))),
                new Node\Arg($n->value ?? new Node\Expr\ConstFetch(new Node\Name('null'))),
                new Node\Arg($thisArg),
            ]
        );

        $n->value = new Node\Expr\Ternary(
            new Node\Expr\Instanceof_(
                new Node\Expr\Assign(new Node\Expr\Variable('__typephpYld'), $checkYieldCall),
                new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorMessage')
            ),
            new Node\Expr\Throw_(
                new Node\Expr\StaticCall(
                    new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorFactory'),
                    'prepareException',
                    [
                        new Node\Arg(
                            new Node\Expr\New_(
                                new Node\Name\FullyQualified('TypePHP\Exception\TypeError'),
                                [
                                    new Node\Arg(
                                        new Node\Expr\MethodCall(new Node\Expr\Variable('__typephpYld'), 'getMessage')
                                    ),
                                ]
                            )
                        ),
                        new Node\Arg(new Node\Scalar\LNumber($n->getStartLine())),
                    ]
                )
            ),
            new Node\Expr\Variable('__typephpYld')
        );

        $checkSendCall = new Node\Expr\FuncCall(
            new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::checkSend'),
            [
                new Node\Arg(new Node\Scalar\MagicConst\Method()),
                new Node\Arg($n),
                new Node\Arg($thisArg),
            ]
        );

        return new Node\Expr\Ternary(
            new Node\Expr\Instanceof_(
                new Node\Expr\Assign(new Node\Expr\Variable('__typephpSnd'), $checkSendCall),
                new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorMessage')
            ),
            new Node\Expr\Throw_(
                new Node\Expr\StaticCall(
                    new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorFactory'),
                    'prepareException',
                    [
                        new Node\Arg(
                            new Node\Expr\New_(
                                new Node\Name\FullyQualified('TypePHP\Exception\TypeError'),
                                [
                                    new Node\Arg(
                                        new Node\Expr\MethodCall(new Node\Expr\Variable('__typephpSnd'), 'getMessage')
                                    ),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new Node\Expr\Variable('__typephpSnd')
        );
    }

    /**
     * @param array<Node\Stmt> $stmts
     *
     * @return array<Node\Stmt>
     */
    private static function wrapGeneratorReturns(array $stmts, Node\Expr $thisArg): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class ($thisArg) extends NodeVisitorAbstract {
            public function __construct(private Node\Expr $thisArg)
            {
            }

            public function enterNode(Node $n): int|Node|null
            {
                if ($n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction || $n instanceof Node\Stmt\Function_ || $n instanceof Node\Stmt\ClassMethod) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                if ($n instanceof Node\Expr\Yield_) {
                    if ($n->getAttribute('typephp_wrapped') === true) {
                        return null;
                    }

                    $n->setAttribute('typephp_wrapped', true);

                    return FunctionContractInjector::buildWrappedYieldNode($n, $this->thisArg);
                }

                if ($n instanceof Node\Expr\YieldFrom) {
                    if ($n->getAttribute('typephp_wrapped') === true) {
                        return null;
                    }

                    $n->setAttribute('typephp_wrapped', true);

                    $n->expr = new Node\Expr\FuncCall(
                        new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::wrapIterable'),
                        [
                            new Node\Arg(new Node\Scalar\MagicConst\Method()),
                            new Node\Arg(new Node\Scalar\String_('return')),
                            new Node\Arg($n->expr),
                            new Node\Arg($this->thisArg),
                        ]
                    );
                }

                return null;
            }
        });

        /** @var array<Node\Stmt> $newStmts */
        $newStmts = $traverser->traverse($stmts);

        return $newStmts;
    }

    /**
     * @param array<Node\Stmt> $stmts
     *
     * @return array<Node\Stmt>
     */
    private static function wrapNonGeneratorReturns(array $stmts, Node\Expr $thisArg, bool $isNativeVoid, bool $needsReturnVars = false): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class ($thisArg, $isNativeVoid, $needsReturnVars) extends NodeVisitorAbstract {
            public function __construct(
                private Node\Expr $thisArg,
                private bool $isNativeVoid,
                private bool $needsReturnVars
            ) {
            }

            public function enterNode(Node $n): int|array|null
            {
                if ($n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction || $n instanceof Node\Stmt\Function_ || $n instanceof Node\Stmt\ClassMethod) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                if ($n instanceof Node\Stmt\Return_) {
                    if ($n->getAttribute('typephp_var_wrapped') === true) {
                        return null;
                    }

                    $exprToWrap = $n->expr ?? new Node\Expr\ConstFetch(new Node\Name('null'));
                    $checkCall = FunctionContractInjector::buildReturnCheckCall($exprToWrap, $this->thisArg, $this->needsReturnVars);

                    if ($this->isNativeVoid) {
                        return FunctionContractInjector::buildVoidReturnGuard($checkCall);
                    }

                    $n->expr = FunctionContractInjector::buildTernaryReturnExpr($checkCall);
                }

                return null;
            }
        });

        /** @var array<Node\Stmt> $newStmts */
        $newStmts = $traverser->traverse($stmts);

        $lastStmt = end($newStmts);
        if (! $lastStmt instanceof Node\Stmt\Return_ && ! ($lastStmt instanceof Node\Stmt\Expression && $lastStmt->expr instanceof Node\Expr\Throw_)) {
            $checkCall = self::buildReturnCheckCall(new Node\Expr\ConstFetch(new Node\Name('null')), $thisArg, $needsReturnVars);

            if ($isNativeVoid) {
                $newStmts = [...$newStmts, ...self::buildVoidReturnGuard($checkCall)];
            } else {
                $retStmt = new Node\Stmt\Return_(self::buildTernaryReturnExpr($checkCall));
                $retStmt->setAttribute('typephp_injected', true);
                $newStmts[] = $retStmt;
            }
        }

        return $newStmts;
    }
}
