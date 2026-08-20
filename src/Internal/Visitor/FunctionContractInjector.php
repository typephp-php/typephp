<?php

declare(strict_types=1);

namespace TypePHP\Internal\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use TypePHP\Internal\Config;

/**
 * @internal Injects parameter checks, return checks, and generator interceptors into functions and methods.
 */
final class FunctionContractInjector
{
    public static function inject(Node\Stmt\Function_|Node\Stmt\ClassMethod $node): void
    {
        if ($node->stmts === null) {
            return;
        }

        $isClassMethod = $node instanceof Node\Stmt\ClassMethod;
        $doc = $node->getDocComment();

        if ($doc === null && ! $isClassMethod) {
            return;
        }

        $docText = $doc !== null ? $doc->getText() : '';

        if (self::shouldSkipInjection($docText)) {
            return;
        }

        $methodName = $isClassMethod ? strtolower($node->name->toString()) : '';
        $isMagicLifecycle = $isClassMethod && \in_array($methodName, ['__construct', '__destruct', '__clone'], true);

        $hasParam = $isClassMethod || str_contains($docText, '@param') || str_contains($docText, '@phpstan-param') || str_contains($docText, '@psalm-param');
        $hasReturn = ! $isMagicLifecycle && ($isClassMethod || str_contains($docText, '@return') || str_contains($docText, '@phpstan-return') || str_contains($docText, '@psalm-return'));

        if (! $hasParam && ! $hasReturn) {
            return;
        }

        $thisArg = self::resolveThisArg($isClassMethod, $node);
        $isNativeVoid = $node->returnType instanceof Node\Identifier && strtolower($node->returnType->name) === 'void';
        $needsReturnVars = str_contains($docText, ' is ') || (str_contains($docText, '@return') && str_contains($docText, '$'));

        $injectedStmts = [];
        if ($hasParam) {
            $injectedStmts = self::buildParamInjections($node->params, $docText, $thisArg, $isClassMethod);
        }

        if ($hasReturn) {
            $node->stmts = self::isGenerator($node)
                ? self::wrapGeneratorReturns($node->stmts, $thisArg)
                : self::wrapNonGeneratorReturns($node->stmts, $thisArg, $isNativeVoid, $needsReturnVars);
        }

        $node->stmts = [...$injectedStmts, ...$node->stmts];
    }

    private static function shouldSkipInjection(string $docText): bool
    {
        $shouldRespectIgnore = (bool) (Config::get()['respect_ignore_tags'] ?? true);

        return $shouldRespectIgnore && (str_contains($docText, '@typephp-ignore') || str_contains($docText, '@typephp-disable'));
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
        Node\Expr $thisArg,
        bool $isClassMethod
    ): array {
        $injectedStmts = [self::buildSetupScopeStmt($thisArg)];

        $callableWrappers = self::buildParamWrappers(
            $params,
            '\TypePHP\Internal\RuntimeTypeChecker::wrapCallable',
            $thisArg,
            $isClassMethod || str_contains($docText, 'callable') || str_contains($docText, 'Closure')
        );

        $iterableWrappers = self::buildParamWrappers(
            $params,
            '\TypePHP\Internal\RuntimeTypeChecker::wrapIterable',
            $thisArg,
            str_contains($docText, 'iterable') || str_contains($docText, 'Traversable') || str_contains($docText, 'Generator') || str_contains($docText, 'Iterator')
        );

        return [...$injectedStmts, ...$callableWrappers, ...$iterableWrappers];
    }

    private static function buildSetupScopeStmt(Node\Expr $thisArg): Node\Stmt\If_
    {
        $checkCall = new Node\Expr\FuncCall(
            new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::setupScope'),
            [
                new Node\Arg(new Node\Scalar\MagicConst\Method()),
                new Node\Arg(new Node\Expr\FuncCall(new Node\Name('get_defined_vars'))),
                new Node\Arg($thisArg),
            ]
        );

        $throwStmt = self::buildTypeErrorThrowStmt(new Node\Expr\Variable('__typephpErr'));

        $ifStmt = new Node\Stmt\If_(
            new Node\Expr\Instanceof_(
                new Node\Expr\Assign(new Node\Expr\Variable('__typephpErr'), $checkCall),
                new Node\Name('\TypePHP\Internal\ErrorMessage')
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
    private static function buildParamWrappers(array $params, string $wrapperFunc, Node\Expr $thisArg, bool $shouldWrap): array
    {
        if (! $shouldWrap) {
            return [];
        }

        $wrappers = [];
        foreach ($params as $param) {
            if ($param->var instanceof Node\Expr\Variable && \is_string($param->var->name)) {
                $paramName = $param->var->name;
                $expr = new Node\Stmt\Expression(
                    new Node\Expr\Assign(
                        new Node\Expr\Variable($paramName),
                        new Node\Expr\FuncCall(
                            new Node\Name($wrapperFunc),
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

    public static function buildTypeErrorThrowStmt(Node\Expr $errorVar): Node\Stmt\Expression
    {
        return new Node\Stmt\Expression(
            new Node\Expr\Throw_(
                new Node\Expr\StaticCall(
                    new Node\Name('\TypePHP\Internal\ErrorFactory'),
                    'prepareException',
                    [
                        new Node\Arg(
                            new Node\Expr\New_(
                                new Node\Name('\TypePHP\Exception\TypeError'),
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
            ? new Node\Expr\FuncCall(new Node\Name('get_defined_vars'))
            : new Node\Expr\Array_();

        return new Node\Expr\FuncCall(
            new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::checkReturn'),
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
                new Node\Name('\TypePHP\Internal\ErrorMessage')
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
                new Node\Name('\TypePHP\Internal\ErrorMessage')
            ),
            new Node\Expr\Throw_(
                new Node\Expr\StaticCall(
                    new Node\Name('\TypePHP\Internal\ErrorFactory'),
                    'prepareException',
                    [
                        new Node\Arg(
                            new Node\Expr\New_(
                                new Node\Name('\TypePHP\Exception\TypeError'),
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
            new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::checkYield'),
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
                new Node\Name('\TypePHP\Internal\ErrorMessage')
            ),
            new Node\Expr\Throw_(
                new Node\Expr\StaticCall(
                    new Node\Name('\TypePHP\Internal\ErrorFactory'),
                    'prepareException',
                    [
                        new Node\Arg(
                            new Node\Expr\New_(
                                new Node\Name('\TypePHP\Exception\TypeError'),
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
            new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::checkSend'),
            [
                new Node\Arg(new Node\Scalar\MagicConst\Method()),
                new Node\Arg($n),
                new Node\Arg($thisArg),
            ]
        );

        return new Node\Expr\Ternary(
            new Node\Expr\Instanceof_(
                new Node\Expr\Assign(new Node\Expr\Variable('__typephpSnd'), $checkSendCall),
                new Node\Name('\TypePHP\Internal\ErrorMessage')
            ),
            new Node\Expr\Throw_(
                new Node\Expr\StaticCall(
                    new Node\Name('\TypePHP\Internal\ErrorFactory'),
                    'prepareException',
                    [
                        new Node\Arg(
                            new Node\Expr\New_(
                                new Node\Name('\TypePHP\Exception\TypeError'),
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
                        new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::wrapIterable'),
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
