<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * @internal Injects parameter checks, return checks, param-out checks, and generator interceptors into functions and methods.
 */
final class FunctionContractInjector
{
    private const ITERABLE_TYPES = [
        'iterable' => true,
        'traversable' => true,
        'generator' => true,
        'iterator' => true,
        'iteratoraggregate' => true,
    ];

    private const CALLABLE_TYPES = [
        'callable' => true,
        'closure' => true,
    ];

    /**
     * @param array{hasInheritance?: bool, hasPropertyWithDoc?: bool, isReadonly?: bool, hasTemplates?: bool}|null $classContext
     */
    public static function inject(Node\Stmt\Function_|Node\Stmt\ClassMethod $node, ?array $classContext = null): void
    {
        if ($node->stmts === null) {
            return;
        }

        $isClassMethod = $node instanceof Node\Stmt\ClassMethod;
        $doc = self::resolveDocComment($node);
        $docText = $doc !== null ? $doc->getText() : '';

        $hasInheritance = $classContext['hasInheritance'] ?? true;
        $hasPropertyWithDoc = $classContext['hasPropertyWithDoc'] ?? true;
        $isReadonlyClass = $classContext['isReadonly'] ?? false;
        $hasClassTemplates = $classContext['hasTemplates'] ?? false;

        $methodName = $isClassMethod ? strtolower($node->name->toString()) : '';
        $isConstructor = $isClassMethod && $methodName === '__construct';
        $isMagicLifecycle = $isClassMethod && \in_array($methodName, ['__construct', '__destruct', '__clone'], true);
        $isNativeNever = $node->returnType instanceof Node\Identifier && strtolower($node->returnType->name) === 'never';
        $isNativeVoid = $node->returnType instanceof Node\Identifier && strtolower($node->returnType->name) === 'void';
        $isPrivate = $isClassMethod && $node->isPrivate();

        $paramCount = \count($node->params);

        $hasParam = self::hasParamContracts(
            $docText,
            $isClassMethod,
            $hasInheritance,
            $paramCount,
            $isPrivate,
            $isConstructor,
            $hasPropertyWithDoc,
            $classContext === null,
            $node->attrGroups !== []
        );

        $byRefParams = [];
        foreach ($node->params as $p) {
            if ($p->byRef && $p->var instanceof Node\Expr\Variable && \is_string($p->var->name)) {
                $byRefParams[] = $p->var->name;
            }
        }

        $hasParamOutDoc = str_contains($docText, '@param-out')
            || str_contains($docText, '@phpstan-param-out')
            || str_contains($docText, '@psalm-param-out');

        $hasParamOut = $byRefParams !== [] && ($hasParamOutDoc || $hasInheritance);

        $hasSelfOutDoc = str_contains($docText, 'self-out') || str_contains($docText, 'this-out');
        $hasSelfOut = $isClassMethod && ! $node->isStatic() && ($hasSelfOutDoc || ($hasClassTemplates && $hasInheritance));

        $hasReturnDoc = str_contains($docText, '@return')
            || str_contains($docText, '@phpstan-return')
            || str_contains($docText, '@psalm-return');

        $hasReturn = ! $isMagicLifecycle
            && ! $isNativeNever
            && ! ($isNativeVoid && ! $hasReturnDoc)
            && self::hasReturnContracts($docText, $isClassMethod, $isPrivate);

        if (! $hasParam && ! $hasReturn && ! $hasParamOut && ! $hasSelfOut) {
            return;
        }

        $thisArg = self::resolveThisArg($isClassMethod, $node);
        $needsReturnVars = $hasParam && ($paramCount > 0) && (
            $hasInheritance || str_contains($docText, ' is ') || ($hasReturnDoc && str_contains($docText, '$'))
        );

        $injectedStmts = [];
        if ($hasParam) {
            $injectedStmts = self::buildParamInjections($node->params, $docText, $thisArg, $isReadonlyClass);
        }

        if ($hasReturn || $hasParamOut || $hasSelfOut) {
            $node->stmts = self::isGenerator($node)
                ? self::wrapGeneratorReturns($node->stmts, $thisArg)
                : self::wrapNonGeneratorReturns(
                    $node->stmts,
                    $thisArg,
                    $isNativeVoid,
                    $needsReturnVars,
                    $hasReturn,
                    $hasParamOut ? $byRefParams : [],
                    $hasSelfOut
                );
        }

        $node->stmts = [...$injectedStmts, ...$node->stmts];
    }

    public static function buildSelfOutCheckStmt(Node\Expr $thisArg, bool $needsReturnVars = false): Node\Stmt\Expression
    {
        $varsArg = $needsReturnVars
            ? new Node\Expr\Variable('_typephpArgs')
            : new Node\Expr\Array_();

        $checkCall = new Node\Expr\FuncCall(
            new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::checkSelfOut'),
            [
                new Node\Arg(new Node\Scalar\MagicConst\Method()),
                new Node\Arg($thisArg),
                new Node\Arg($varsArg),
            ]
        );

        $stmt = new Node\Stmt\Expression($checkCall);
        $stmt->setAttribute('typephp_injected', true);

        return $stmt;
    }

    private static function resolveDocComment(Node\Stmt\Function_|Node\Stmt\ClassMethod $node): ?Doc
    {
        $doc = $node->getDocComment();
        if ($doc !== null) {
            return $doc;
        }

        if ($node->attrGroups !== []) {
            foreach ($node->attrGroups as $group) {
                $groupDoc = $group->getDocComment();
                if ($groupDoc !== null) {
                    return $groupDoc;
                }
            }
        }

        return null;
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
            return $isDirectUnitTest && $isClassMethod;
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
            return self::hasNonMixedParam($docText);
        }

        return $isClassMethod && ! $isPrivate;
    }

    private static function hasNonMixedParam(string $docText): bool
    {
        if ((int) preg_match_all('/@param\s+([^\s$]+)/', $docText, $matches) === 0) {
            return false;
        }

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

        return false;
    }

    private static function hasReturnContracts(
        string $docText,
        bool $isClassMethod,
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
        $visitor = new class() extends NodeVisitorAbstract {
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
        /** @var array<Node\Stmt> $stmts */
        $stmts = $node->stmts;
        $traverser->traverse($stmts);

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
        Node\Expr $thisArg,
        bool $isReadonlyClass = false
    ): array {
        $injectedStmts = [self::buildSetupScopeStmt($params, $thisArg)];
        $callableWrappers = self::buildParamWrappers($params, $docText, $thisArg, [self::class, 'isCallableCandidate'], 'wrapCallable', $isReadonlyClass);
        $iterableWrappers = self::buildParamWrappers($params, $docText, $thisArg, [self::class, 'isIterableCandidate'], 'wrapIterable', $isReadonlyClass);

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

        $argsAssign = new Node\Stmt\Expression(
            new Node\Expr\Assign(
                new Node\Expr\Variable('_typephpArgs'),
                new Node\Expr\Array_($arrayItems)
            )
        );

        $checkCall = new Node\Expr\FuncCall(
            new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::setupScope'),
            [
                new Node\Arg(new Node\Scalar\MagicConst\Method()),
                new Node\Arg(new Node\Expr\Variable('_typephpArgs')),
                new Node\Arg($thisArg),
            ]
        );

        $throwStmt = self::buildTypeErrorThrowStmt(new Node\Expr\Variable('__typephpErr'));

        $cacheKeyExpr = new Node\Scalar\MagicConst\Method();

        $noParamCacheCheck = new Node\Expr\BooleanNot(
            new Node\Expr\Isset_([
                new Node\Expr\ArrayDimFetch(
                    new Node\Expr\StaticPropertyFetch(
                        new Node\Name\FullyQualified('TypePHP\Internal\Checker\ParamChecker'),
                        'noParamContractCache'
                    ),
                    $cacheKeyExpr
                ),
            ])
        );

        $hasTemplatesCheck = new Node\Expr\BinaryOp\Coalesce(
            new Node\Expr\ArrayDimFetch(
                new Node\Expr\StaticPropertyFetch(
                    new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker'),
                    'hasMethodTemplatesCache'
                ),
                $cacheKeyExpr
            ),
            new Node\Expr\ConstFetch(new Node\Name('false'))
        );

        $combinedCondition = new Node\Expr\BinaryOp\BooleanOr($noParamCacheCheck, $hasTemplatesCheck);

        $ifStmt = new Node\Stmt\If_(
            $combinedCondition,
            [
                'stmts' => [
                    $argsAssign,
                    new Node\Stmt\If_(
                        new Node\Expr\Instanceof_(
                            new Node\Expr\Assign(new Node\Expr\Variable('__typephpErr'), $checkCall),
                            new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorMessage')
                        ),
                        ['stmts' => [$throwStmt]]
                    ),
                ],
            ]
        );

        $ifStmt->setAttribute('typephp_injected', true);

        return $ifStmt;
    }

    /**
     * @param array<string> $byRefParamNames
     *
     * @return array<Node\Stmt>
     */
    public static function buildParamOutCheckStmts(array $byRefParamNames, Node\Expr $thisArg): array
    {
        $stmts = [];
        foreach ($byRefParamNames as $pName) {
            $checkCall = new Node\Expr\FuncCall(
                new Node\Name\FullyQualified('TypePHP\Internal\RuntimeTypeChecker::checkParamOut'),
                [
                    new Node\Arg(new Node\Scalar\MagicConst\Method()),
                    new Node\Arg(new Node\Scalar\String_($pName)),
                    new Node\Arg(new Node\Expr\Variable($pName)),
                    new Node\Arg($thisArg),
                ]
            );

            $ifStmt = new Node\Stmt\If_(
                new Node\Expr\Instanceof_(
                    new Node\Expr\Assign(new Node\Expr\Variable('__typephpErr'), $checkCall),
                    new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorMessage')
                ),
                ['stmts' => [self::buildTypeErrorThrowStmt(new Node\Expr\Variable('__typephpErr'))]]
            );
            $ifStmt->setAttribute('typephp_injected', true);
            $stmts[] = $ifStmt;
        }

        return $stmts;
    }

    /**
     * @param array<Node\Param> $params
     * @param callable(Node\Param, string): bool $predicate
     *
     * @return array<Node\Stmt>
     */
    private static function buildParamWrappers(
        array $params,
        string $docText,
        Node\Expr $thisArg,
        callable $predicate,
        string $wrapperMethod,
        bool $isReadonlyClass = false
    ): array {
        $wrappers = [];

        foreach ($params as $param) {
            if ($predicate($param, $docText) && $param->var instanceof Node\Expr\Variable && \is_string($param->var->name)) {
                $paramName = $param->var->name;
                $assignExpr = new Node\Expr\Assign(
                    new Node\Expr\Variable($paramName),
                    new Node\Expr\FuncCall(
                        new Node\Name\FullyQualified("TypePHP\Internal\RuntimeTypeChecker::{$wrapperMethod}"),
                        [
                            new Node\Arg(new Node\Scalar\MagicConst\Method()),
                            new Node\Arg(new Node\Scalar\String_($paramName)),
                            new Node\Arg(new Node\Expr\Variable($paramName)),
                            new Node\Arg($thisArg),
                        ]
                    )
                );

                $expr = new Node\Stmt\Expression($assignExpr);
                $expr->setAttribute('typephp_injected', true);
                $wrappers[] = $expr;

                $isReadonlyParam = ($param->flags & Node\Stmt\Class_::MODIFIER_READONLY) !== 0;
                $isReadonly = $isReadonlyParam || $isReadonlyClass;

                if ($param->isPromoted() && ! $isReadonly) {
                    $propAssign = new Node\Stmt\Expression(
                        new Node\Expr\Assign(
                            new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $paramName),
                            new Node\Expr\Variable($paramName)
                        )
                    );
                    $propAssign->setAttribute('typephp_injected', true);
                    $wrappers[] = $propAssign;
                }
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

        return self::typeMatchesName($param->type, self::CALLABLE_TYPES);
    }

    private static function isIterableCandidate(Node\Param $param, string $docText): bool
    {
        if (self::typeMatchesName($param->type, self::ITERABLE_TYPES)) {
            return true;
        }

        $paramName = $param->var instanceof Node\Expr\Variable && \is_string($param->var->name) ? $param->var->name : '';
        if ($paramName !== '' && preg_match('/@(?:param|phpstan-param|psalm-param)\s+[^\$]*?(?:iterable|Traversable|Generator|Iterator)\b[^\$]*?\$' . preg_quote($paramName, '/') . '\b/i', $docText) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, true> $targetNames
     */
    private static function typeMatchesName(Node\Identifier|Node\Name|Node\ComplexType|null $type, array $targetNames): bool
    {
        if ($type instanceof Node\Identifier) {
            return isset($targetNames[strtolower($type->name)]);
        }
        if ($type instanceof Node\Name) {
            return isset($targetNames[strtolower($type->getLast())]);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $t) {
                if (self::typeMatchesName($t, $targetNames)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function buildTypeErrorThrowExpr(Node\Expr $errorVar, ?int $line = null): Node\Expr\Throw_
    {
        $args = [
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
        ];

        if ($line !== null) {
            $args[] = new Node\Arg(new Node\Scalar\LNumber($line));
        }

        return new Node\Expr\Throw_(
            new Node\Expr\StaticCall(
                new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorFactory'),
                'prepareException',
                $args
            )
        );
    }

    public static function buildTypeErrorThrowStmt(Node\Expr $errorVar): Node\Stmt\Expression
    {
        return new Node\Stmt\Expression(self::buildTypeErrorThrowExpr($errorVar));
    }

    public static function buildReturnCheckCall(Node\Expr $exprToWrap, Node\Expr $thisArg, bool $needsReturnVars = false): Node\Expr\FuncCall
    {
        $varsArg = $needsReturnVars
            ? new Node\Expr\Variable('_typephpArgs')
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

    public static function buildTernaryReturnExpr(Node\Expr\FuncCall $checkCall, ?int $line = null): Node\Expr\Ternary
    {
        return new Node\Expr\Ternary(
            new Node\Expr\Instanceof_(
                new Node\Expr\Assign(new Node\Expr\Variable('__typephpRet'), $checkCall),
                new Node\Name\FullyQualified('TypePHP\Internal\Diagnostic\ErrorMessage')
            ),
            self::buildTypeErrorThrowExpr(new Node\Expr\Variable('__typephpRet'), $line),
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
            self::buildTypeErrorThrowExpr(new Node\Expr\Variable('__typephpYld'), $n->getStartLine()),
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
            self::buildTypeErrorThrowExpr(new Node\Expr\Variable('__typephpSnd')),
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
        $traverser->addVisitor(new class($thisArg) extends NodeVisitorAbstract {
            public function __construct(private Node\Expr $thisArg) {}

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
     * @param array<string> $byRefParams
     *
     * @return array<Node\Stmt>
     */
    private static function wrapNonGeneratorReturns(
        array $stmts,
        Node\Expr $thisArg,
        bool $isNativeVoid,
        bool $needsReturnVars = false,
        bool $hasReturn = true,
        array $byRefParams = [],
        bool $hasSelfOut = false
    ): array {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($thisArg, $isNativeVoid, $needsReturnVars, $hasReturn, $byRefParams, $hasSelfOut) extends NodeVisitorAbstract {
            /**
             * @param array<string> $byRefParams
             */
            public function __construct(
                private Node\Expr $thisArg,
                private bool $isNativeVoid,
                private bool $needsReturnVars,
                private bool $hasReturn,
                private array $byRefParams,
                private bool $hasSelfOut
            ) {}

            public function enterNode(Node $n): int|array|null
            {
                if ($n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction || $n instanceof Node\Stmt\Function_ || $n instanceof Node\Stmt\ClassMethod) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                if ($n instanceof Node\Stmt\Return_) {
                    if ($n->getAttribute('typephp_var_wrapped') === true) {
                        return null;
                    }

                    $exitStmts = [];
                    if ($this->hasSelfOut) {
                        $exitStmts[] = FunctionContractInjector::buildSelfOutCheckStmt($this->thisArg, $this->needsReturnVars);
                    }

                    $paramOutStmts = $this->byRefParams !== []
                        ? FunctionContractInjector::buildParamOutCheckStmts($this->byRefParams, $this->thisArg)
                        : [];

                    if ($paramOutStmts !== []) {
                        $exitStmts = [...$exitStmts, ...$paramOutStmts];
                    }

                    if (! $this->hasReturn) {
                        return $exitStmts !== [] ? [...$exitStmts, $n] : null;
                    }

                    $exprToWrap = $n->expr ?? new Node\Expr\ConstFetch(new Node\Name('null'));

                    if ($this->isNativeVoid) {
                        $checkCall = FunctionContractInjector::buildReturnCheckCall($exprToWrap, $this->thisArg, $this->needsReturnVars);
                        $voidGuardStmts = FunctionContractInjector::buildVoidReturnGuard($checkCall);

                        return [...$exitStmts, ...$voidGuardStmts];
                    }

                    $checkCall = FunctionContractInjector::buildReturnCheckCall($exprToWrap, $this->thisArg, $this->needsReturnVars);
                    $n->expr = FunctionContractInjector::buildTernaryReturnExpr($checkCall, $n->getStartLine());

                    return $exitStmts !== [] ? [...$exitStmts, $n] : null;
                }

                return null;
            }
        });

        /** @var array<Node\Stmt> $newStmts */
        $newStmts = $traverser->traverse($stmts);

        $lastStmt = end($newStmts);
        if (! $lastStmt instanceof Node\Stmt\Return_ && ! ($lastStmt instanceof Node\Stmt\Expression && $lastStmt->expr instanceof Node\Expr\Throw_)) {
            $exitStmts = [];
            if ($hasSelfOut) {
                $exitStmts[] = self::buildSelfOutCheckStmt($thisArg, $needsReturnVars);
            }

            $paramOutStmts = $byRefParams !== []
                ? self::buildParamOutCheckStmts($byRefParams, $thisArg)
                : [];

            if ($paramOutStmts !== []) {
                $exitStmts = [...$exitStmts, ...$paramOutStmts];
            }

            if (! $hasReturn) {
                if ($exitStmts !== []) {
                    $newStmts = [...$newStmts, ...$exitStmts];
                }
            } else {
                $checkCall = self::buildReturnCheckCall(new Node\Expr\ConstFetch(new Node\Name('null')), $thisArg, $needsReturnVars);

                if ($isNativeVoid) {
                    $newStmts = [...$newStmts, ...$exitStmts, ...self::buildVoidReturnGuard($checkCall)];
                } else {
                    $fallbackLine = $lastStmt instanceof Node\Stmt ? $lastStmt->getStartLine() : null;
                    $ternaryExpr = self::buildTernaryReturnExpr($checkCall, $fallbackLine);

                    $retStmt = new Node\Stmt\Return_($ternaryExpr);
                    $retStmt->setAttribute('typephp_injected', true);
                    $newStmts = [...$newStmts, ...$exitStmts, $retStmt];
                }
            }
        }

        return $newStmts;
    }
}
