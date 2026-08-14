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

        // Per-Function/Method Suppression Tag
        if ((bool) (Config::get()['respect_ignore_tags'] ?? true) && (str_contains($docText, '@typephp-ignore') || str_contains($docText, '@typephp-disable'))) {
            return; // Skip injecting contract checks for this specific function/method!
        }

        $methodName = $isClassMethod ? strtolower($node->name->toString()) : '';
        $isMagicLifecycle = $isClassMethod && \in_array($methodName, ['__construct', '__destruct', '__clone'], true);

        $hasParam = $isClassMethod || str_contains($docText, '@param');

        // Never inject return checks into constructors, destructors, or clone methods
        $hasReturn = ! $isMagicLifecycle && ($isClassMethod || str_contains($docText, '@return') || str_contains($docText, '@phpstan-return') || str_contains($docText, '@psalm-return'));

        if (! $hasParam && ! $hasReturn) {
            return;
        }

        $isNativeVoid = $node->returnType instanceof Node\Identifier && strtolower($node->returnType->name) === 'void';
        $hasThis = $isClassMethod && ! $node->isStatic();

        // Pass $this for instance methods, static::class for static methods, or null for global functions
        $thisArg = $hasThis
            ? new Node\Expr\Variable('this')
            : ($isClassMethod ? new Node\Expr\ClassConstFetch(new Node\Name('static'), 'class') : new Node\Expr\ConstFetch(new Node\Name('null')));

        $injectedStmts = [];

        if ($hasParam) {
            $injectedStmts = self::buildParamInjections($node, $docText, $thisArg, $isClassMethod);
        }

        if ($hasReturn) {
            $node->stmts = self::isGenerator($node)
                ? self::wrapGeneratorReturns($node->stmts)
                : self::wrapNonGeneratorReturns($node->stmts, $thisArg, $isNativeVoid);
        }

        $node->stmts = array_merge($injectedStmts, $node->stmts);
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
     * @return array<Node\Stmt>
     */
    private static function buildParamInjections(
        Node\Stmt\Function_|Node\Stmt\ClassMethod $node,
        string $docText,
        Node\Expr $thisArg,
        bool $isClassMethod
    ): array {
        $injectedStmts = [];

        $ifStmt = new Node\Stmt\If_(
            new Node\Expr\Instanceof_(
                new Node\Expr\Assign(
                    new Node\Expr\Variable('__typephpErr'),
                    new Node\Expr\FuncCall(
                        new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::setupScope'),
                        [
                            new Node\Arg(new Node\Scalar\MagicConst\Method()),
                            new Node\Arg(new Node\Expr\FuncCall(new Node\Name('get_defined_vars'))),
                            new Node\Arg($thisArg),
                        ]
                    )
                ),
                new Node\Name('\TypePHP\Internal\ErrorMessage')
            ),
            [
                'stmts' => [
                    new Node\Stmt\Expression(
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
                                                    new Node\Expr\MethodCall(
                                                        new Node\Expr\Variable('__typephpErr'),
                                                        'getMessage'
                                                    )
                                                ),
                                            ]
                                        )
                                    ),
                                ]
                            )
                        )
                    ),
                ],
            ]
        );

        $ifStmt->setAttribute('typephp_injected', true);
        $injectedStmts[] = $ifStmt;

        if ($isClassMethod || str_contains($docText, 'callable') || str_contains($docText, 'Closure')) {
            foreach ($node->params as $param) {
                if ($param->var instanceof Node\Expr\Variable && \is_string($param->var->name)) {
                    $paramName = $param->var->name;
                    $expr = new Node\Stmt\Expression(
                        new Node\Expr\Assign(
                            new Node\Expr\Variable($paramName),
                            new Node\Expr\FuncCall(
                                new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::wrapCallable'),
                                [
                                    new Node\Arg(new Node\Scalar\MagicConst\Method()),
                                    new Node\Arg(new Node\Scalar\String_($paramName)),
                                    new Node\Arg(new Node\Expr\Variable($paramName)),
                                ]
                            )
                        )
                    );
                    $expr->setAttribute('typephp_injected', true);
                    $injectedStmts[] = $expr;
                }
            }
        }

        if ($isClassMethod || str_contains($docText, 'iterable') || str_contains($docText, 'Traversable') || str_contains($docText, 'Generator') || str_contains($docText, 'Iterator')) {
            foreach ($node->params as $param) {
                if ($param->var instanceof Node\Expr\Variable && \is_string($param->var->name)) {
                    $paramName = $param->var->name;
                    $expr = new Node\Stmt\Expression(
                        new Node\Expr\Assign(
                            new Node\Expr\Variable($paramName),
                            new Node\Expr\FuncCall(
                                new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::wrapIterable'),
                                [
                                    new Node\Arg(new Node\Scalar\MagicConst\Method()),
                                    new Node\Arg(new Node\Scalar\String_($paramName)),
                                    new Node\Arg(new Node\Expr\Variable($paramName)),
                                ]
                            )
                        )
                    );
                    $expr->setAttribute('typephp_injected', true);
                    $injectedStmts[] = $expr;
                }
            }
        }

        return $injectedStmts;
    }

    /**
     * @param array<Node\Stmt> $stmts
     *
     * @return array<Node\Stmt>
     */
    private static function wrapGeneratorReturns(array $stmts): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class () extends NodeVisitorAbstract {
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

                    $checkYieldCall = new Node\Expr\FuncCall(
                        new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::checkYield'),
                        [
                            new Node\Arg(new Node\Scalar\MagicConst\Method()),
                            new Node\Arg($n->key ?? new Node\Expr\ConstFetch(new Node\Name('null'))),
                            new Node\Arg($n->value ?? new Node\Expr\ConstFetch(new Node\Name('null'))),
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
    private static function wrapNonGeneratorReturns(array $stmts, Node\Expr $thisArg, bool $isNativeVoid): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class ($thisArg, $isNativeVoid) extends NodeVisitorAbstract {
            public function __construct(
                private Node\Expr $thisArg,
                private bool $isNativeVoid
            ) {
            }

            public function enterNode(Node $n): int|array|null
            {
                if ($n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction || $n instanceof Node\Stmt\Function_ || $n instanceof Node\Stmt\ClassMethod) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                if ($n instanceof Node\Stmt\Return_) {
                    $exprToWrap = $n->expr ?? new Node\Expr\ConstFetch(new Node\Name('null'));

                    $checkReturnCall = new Node\Expr\FuncCall(
                        new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::checkReturn'),
                        [
                            new Node\Arg(new Node\Scalar\MagicConst\Method()),
                            new Node\Arg($exprToWrap),
                            new Node\Arg($this->thisArg),
                            new Node\Arg(new Node\Expr\FuncCall(new Node\Name('get_defined_vars'))),
                        ]
                    );

                    if ($this->isNativeVoid) {
                        $ifStmt = new Node\Stmt\If_(
                            new Node\Expr\Instanceof_(
                                new Node\Expr\Assign(
                                    new Node\Expr\Variable('__typephpRet'),
                                    $checkReturnCall
                                ),
                                new Node\Name('\TypePHP\Internal\ErrorMessage')
                            ),
                            [
                                'stmts' => [
                                    new Node\Stmt\Expression(
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
                                                                    new Node\Expr\MethodCall(
                                                                        new Node\Expr\Variable('__typephpRet'),
                                                                        'getMessage'
                                                                    )
                                                                ),
                                                            ]
                                                        )
                                                    ),
                                                ]
                                            )
                                        )
                                    ),
                                ],
                            ]
                        );
                        $ifStmt->setAttribute('typephp_injected', true);

                        $retStmt = new Node\Stmt\Return_(null);
                        $retStmt->setAttribute('typephp_injected', true);

                        return [
                            $ifStmt,
                            $retStmt,
                        ];
                    }

                    $n->expr = new Node\Expr\Ternary(
                        new Node\Expr\Instanceof_(
                            new Node\Expr\Assign(
                                new Node\Expr\Variable('__typephpRet'),
                                $checkReturnCall
                            ),
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
                                                    new Node\Expr\MethodCall(
                                                        new Node\Expr\Variable('__typephpRet'),
                                                        'getMessage'
                                                    )
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

                return null;
            }
        });

        /** @var array<Node\Stmt> $newStmts */
        $newStmts = $traverser->traverse($stmts);

        $lastStmt = end($newStmts);
        if (! $lastStmt instanceof Node\Stmt\Return_ && ! ($lastStmt instanceof Node\Stmt\Expression && $lastStmt->expr instanceof Node\Expr\Throw_)) {
            $checkReturnCall = new Node\Expr\FuncCall(
                new Node\Name('\TypePHP\Internal\RuntimeTypeChecker::checkReturn'),
                [
                    new Node\Arg(new Node\Scalar\MagicConst\Method()),
                    new Node\Arg(new Node\Expr\ConstFetch(new Node\Name('null'))),
                    new Node\Arg($thisArg),
                    new Node\Arg(new Node\Expr\FuncCall(new Node\Name('get_defined_vars'))),
                ]
            );

            if ($isNativeVoid) {
                $ifStmt = new Node\Stmt\If_(
                    new Node\Expr\Instanceof_(
                        new Node\Expr\Assign(
                            new Node\Expr\Variable('__typephpRet'),
                            $checkReturnCall
                        ),
                        new Node\Name('\TypePHP\Internal\ErrorMessage')
                    ),
                    [
                        'stmts' => [
                            new Node\Stmt\Expression(
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
                                                            new Node\Expr\MethodCall(
                                                                new Node\Expr\Variable('__typephpRet'),
                                                                'getMessage'
                                                            )
                                                        ),
                                                    ]
                                                )
                                            ),
                                        ]
                                    )
                                )
                            ),
                        ],
                    ]
                );
                $ifStmt->setAttribute('typephp_injected', true);
                $newStmts[] = $ifStmt;

                $retStmt = new Node\Stmt\Return_(null);
                $retStmt->setAttribute('typephp_injected', true);
                $newStmts[] = $retStmt;
            } else {
                $retStmt = new Node\Stmt\Return_(
                    new Node\Expr\Ternary(
                        new Node\Expr\Instanceof_(
                            new Node\Expr\Assign(
                                new Node\Expr\Variable('__typephpRet'),
                                $checkReturnCall
                            ),
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
                                                    new Node\Expr\MethodCall(
                                                        new Node\Expr\Variable('__typephpRet'),
                                                        'getMessage'
                                                    )
                                                ),
                                            ]
                                        )
                                    ),
                                ]
                            )
                        ),
                        new Node\Expr\Variable('__typephpRet')
                    )
                );
                $retStmt->setAttribute('typephp_injected', true);
                $newStmts[] = $retStmt;
            }
        }

        return $newStmts;
    }
}
