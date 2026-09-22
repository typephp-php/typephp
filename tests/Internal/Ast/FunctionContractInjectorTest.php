<?php

declare(strict_types=1);

use PhpParser\Comment\Doc;
use PhpParser\Node;
use TypePHP\Internal\Ast\FunctionContractInjector;
use TypePHP\Internal\Util\Config;

describe('FunctionContractInjector Unit Tests', function () {
    beforeEach(function () {
        Config::reset();
    });

    afterEach(function () {
        Config::reset();
    });

    describe('Native : never Return Type Handling', function () {
        test('does not inject return statements into methods with native : never return type (Tempest MockClock::dd pattern)', function () {
            $method = new Node\Stmt\ClassMethod('dd', [
                'returnType' => new Node\Identifier('never'),
                'stmts' => [
                    new Node\Stmt\Expression(new Node\Expr\FuncCall(new Node\Name('dd'))),
                ],
            ]);

            FunctionContractInjector::inject($method);

            $hasReturn = false;
            foreach ($method->stmts ?? [] as $stmt) {
                if ($stmt instanceof Node\Stmt\Return_) {
                    $hasReturn = true;
                }
            }

            expect($hasReturn)->toBeFalse();
        });

        test('transforms code containing native : never methods without injecting return statements into AST', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

class NeverMethodFixture
{
    /**
     * @param positive-int $code
     */
    public function terminate(int $code): never
    {
        throw new RuntimeException("Terminated: {$code}");
    }

    public function dd(): never
    {
        exit(1);
    }
}
PHP;

            $transformed = TypePHP\Internal\Io\StreamWrapper::transformSource($source, 'test_never_method.php');

            expect($transformed)->toContain('RuntimeTypeChecker::setupScope')
                ->and($transformed)->not()->toContain('return ($__typephpRet')
                ->and($transformed)->not()->toContain('return null;')
            ;
        });
    });

    describe('Parameter Injections', function () {
        test('injects setupScope and return check into function with docblocks', function () {
            $doc = new Doc('/** @param positive-int $id @return non-empty-string */');

            $fn = new Node\Stmt\Function_('testUser', [
                'params' => [
                    new Node\Param(new Node\Expr\Variable('id'), null, new Node\Identifier('int')),
                ],
                'stmts' => [
                    new Node\Stmt\Return_(new Node\Scalar\String_('alice')),
                ],
            ], [
                'comments' => [$doc],
            ]);

            FunctionContractInjector::inject($fn);

            expect($fn->stmts)->not()->toBeEmpty();

            $firstStmt = $fn->stmts[0];
            expect($firstStmt)->toBeInstanceOf(Node\Stmt\If_::class)
                ->and($firstStmt->getAttribute('typephp_injected'))->toBeTrue()
            ;
        });

        test('injects wrapCallable and wrapIterable for parameters matching keywords', function () {
            $doc = new Doc('/** @param callable(int): string $cb @param iterable<string> $items */');

            $fn = new Node\Stmt\Function_('processData', [
                'params' => [
                    new Node\Param(new Node\Expr\Variable('cb')),
                    new Node\Param(new Node\Expr\Variable('items')),
                ],
                'stmts' => [],
            ], [
                'comments' => [$doc],
            ]);

            FunctionContractInjector::inject($fn);

            expect(\count($fn->stmts))->toBeGreaterThanOrEqual(3)
                ->and($fn->stmts[1]->getAttribute('typephp_injected'))->toBeTrue()
                ->and($fn->stmts[2]->getAttribute('typephp_injected'))->toBeTrue()
            ;
        });

        test('does not inject wrapIterable when docblock does not contain iterable keywords', function () {
            $doc = new Doc('/** @param positive-int $id */');

            $fn = new Node\Stmt\Function_('simpleFunc', [
                'params' => [
                    new Node\Param(new Node\Expr\Variable('id')),
                ],
                'stmts' => [],
            ], [
                'comments' => [$doc],
            ]);

            FunctionContractInjector::inject($fn);

            expect(\count($fn->stmts))->toBe(1);
        });
    });

    describe('Non-Generator Return Wrapping', function () {
        test('wraps standard return expressions in ternary checkReturn', function () {
            $doc = new Doc('/** @return non-empty-string */');

            $fn = new Node\Stmt\Function_('getName', [
                'stmts' => [
                    new Node\Stmt\Return_(new Node\Scalar\String_('Alice')),
                ],
            ], [
                'comments' => [$doc],
            ]);

            FunctionContractInjector::inject($fn);

            $returnStmt = $fn->stmts[0];
            expect($returnStmt)->toBeInstanceOf(Node\Stmt\Return_::class)
                ->and($returnStmt->expr)->toBeInstanceOf(Node\Expr\Ternary::class)
            ;
        });

        test('injects checkParamOut before return statements when function has by-ref param with @param-out', function () {
            $doc = new Doc("/**\n * @param mixed &\$val\n * @param-out positive-int \$val\n */");

            $fn = new Node\Stmt\Function_('testParamOutInjection', [
                'params' => [
                    new Node\Param(
                        var: new Node\Expr\Variable('val'),
                        byRef: true
                    ),
                ],
                'stmts' => [
                    new Node\Stmt\Return_(null),
                ],
            ], [
                'comments' => [$doc],
            ]);

            FunctionContractInjector::inject($fn);

            $hasCheckParamOut = false;
            foreach ($fn->stmts ?? [] as $stmt) {
                if ($stmt instanceof Node\Stmt\If_ && $stmt->cond instanceof Node\Expr\Instanceof_) {
                    $assign = $stmt->cond->expr;
                    if ($assign instanceof Node\Expr\Assign && $assign->expr instanceof Node\Expr\FuncCall) {
                        if ($assign->expr->name->toString() === 'TypePHP\Internal\RuntimeTypeChecker::checkParamOut') {
                            $hasCheckParamOut = true;
                        }
                    }
                }
            }

            expect($hasCheckParamOut)->toBeTrue();
        });

        test('wraps native void return statements in if check with null return', function () {
            $doc = new Doc('/** @return void */');

            $fn = new Node\Stmt\Function_('processVoid', [
                'returnType' => new Node\Identifier('void'),
                'stmts' => [
                    new Node\Stmt\Return_(null),
                ],
            ], [
                'comments' => [$doc],
            ]);

            FunctionContractInjector::inject($fn);

            expect($fn->stmts[0])->toBeInstanceOf(Node\Stmt\If_::class)
                ->and($fn->stmts[1])->toBeInstanceOf(Node\Stmt\Return_::class)
            ;
        });

        test('appends implicit trailing return check when function has no return statement', function () {
            $doc = new Doc('/** @return non-empty-string */');

            $fn = new Node\Stmt\Function_('noReturnFunc', [
                'stmts' => [
                    new Node\Stmt\Expression(new Node\Expr\Variable('x')),
                ],
            ], [
                'comments' => [$doc],
            ]);

            FunctionContractInjector::inject($fn);

            $lastStmt = end($fn->stmts);
            expect($lastStmt)->toBeInstanceOf(Node\Stmt\Return_::class)
                ->and($lastStmt->getAttribute('typephp_injected'))->toBeTrue()
            ;
        });
    });

    describe('Generator Return and Yield Wrapping', function () {
        test('wraps yield expressions with checkYield and checkSend', function () {
            $doc = new Doc('/** @return Generator<string, positive-int> */');

            $fn = new Node\Stmt\Function_('genFunc', [
                'stmts' => [
                    new Node\Stmt\Expression(
                        new Node\Expr\Yield_(new Node\Scalar\LNumber(10), new Node\Scalar\String_('a'))
                    ),
                ],
            ], [
                'comments' => [$doc],
            ]);

            FunctionContractInjector::inject($fn);

            $yieldExpr = $fn->stmts[0]->expr;
            expect($yieldExpr)->toBeInstanceOf(Node\Expr\Ternary::class);
        });

        test('wraps yield from expressions with wrapIterable', function () {
            $doc = new Doc('/** @return Generator<string, positive-int> */');

            $fn = new Node\Stmt\Function_('yieldFromFunc', [
                'stmts' => [
                    new Node\Stmt\Expression(
                        new Node\Expr\YieldFrom(new Node\Expr\Array_())
                    ),
                ],
            ], [
                'comments' => [$doc],
            ]);

            FunctionContractInjector::inject($fn);

            $yieldFrom = $fn->stmts[0]->expr;
            expect($yieldFrom)->toBeInstanceOf(Node\Expr\YieldFrom::class)
                ->and($yieldFrom->expr)->toBeInstanceOf(Node\Expr\FuncCall::class)
            ;
        });
    });

    describe('Static vs Instance Methods & Lifecycles', function () {
        test('resolves thisArg to static::class for static methods', function () {
            $method = new Node\Stmt\ClassMethod('staticMethod', [
                'flags' => Node\Stmt\Class_::MODIFIER_PUBLIC | Node\Stmt\Class_::MODIFIER_STATIC,
                'stmts' => [],
            ]);

            FunctionContractInjector::inject($method);

            $setupIf = $method->stmts[0];
            expect($setupIf)->toBeInstanceOf(Node\Stmt\If_::class);
        });

        test('does not inject return checks into magic lifecycle methods like __construct, __destruct, __clone', function () {
            $lifecycleMethods = ['__construct', '__destruct', '__clone'];

            foreach ($lifecycleMethods as $name) {
                $method = new Node\Stmt\ClassMethod($name, [
                    'params' => [
                        new Node\Param(new Node\Expr\Variable('id')),
                    ],
                    'stmts' => [],
                ]);

                FunctionContractInjector::inject($method);

                $hasReturn = false;
                foreach ($method->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\Return_) {
                        $hasReturn = true;
                    }
                }

                expect($hasReturn)->toBeFalse();
            }
        });
    });

    describe('Ignore Tag Suppression (@typephp-ignore)', function () {
        test('injects setupScope hook so @typephp-ignore can be resolved dynamically at runtime by DocblockParser', function () {
            $doc = new Doc("/**\n * @typephp-ignore\n * @param positive-int \$id\n */");

            $method = new Node\Stmt\ClassMethod('ignoredMethod', [
                'params' => [
                    new Node\Param(new Node\Expr\Variable('id')),
                ],
                'stmts' => [],
            ], [
                'comments' => [$doc],
            ]);

            FunctionContractInjector::inject($method);

            expect($method->stmts)->not()->toBeEmpty()
                ->and($method->stmts[0])->toBeInstanceOf(Node\Stmt\If_::class)
                ->and($method->stmts[0]->getAttribute('typephp_injected'))->toBeTrue()
            ;
        });
    });

    describe('DocComment & Native Union Type Extraction Coverage', function () {
        test('resolves doc comments from node comments and attribute groups', function () {
            $doc = new Doc('/** @param positive-int $id */');
            $fnWithDoc = new Node\Stmt\Function_('fnDoc', [
                'params' => [new Node\Param(new Node\Expr\Variable('id'))],
                'stmts' => [],
            ], [
                'comments' => [$doc],
            ]);

            FunctionContractInjector::inject($fnWithDoc);
            expect($fnWithDoc->stmts[0]->getAttribute('typephp_injected'))->toBeTrue();

            $attr = new Node\Attribute(new Node\Name('Route'));
            $attrGroupWithDoc = new Node\AttributeGroup([$attr], [
                'comments' => [$doc],
            ]);
            $fnWithAttrDoc = new Node\Stmt\Function_('fnAttrDoc', [
                'attrGroups' => [$attrGroupWithDoc],
                'params' => [new Node\Param(new Node\Expr\Variable('id'))],
                'stmts' => [],
            ]);

            FunctionContractInjector::inject($fnWithAttrDoc);
            expect($fnWithAttrDoc->stmts[0]->getAttribute('typephp_injected'))->toBeTrue();
        });

        test('detects callable and iterable candidates in native union parameter types without docblocks', function () {
            $unionCallableParam = new Node\Param(
                new Node\Expr\Variable('cb'),
                null,
                new Node\UnionType([
                    new Node\Identifier('callable'),
                    new Node\Identifier('string'),
                ])
            );

            $methodCallableUnion = new Node\Stmt\ClassMethod('testUnionCb', [
                'params' => [$unionCallableParam],
                'stmts' => [],
            ]);

            FunctionContractInjector::inject($methodCallableUnion);
            expect($methodCallableUnion->stmts)->not()->toBeEmpty();

            $hasWrapCallable = false;
            foreach ($methodCallableUnion->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof Node\Expr\Assign) {
                    if ($stmt->expr->expr instanceof Node\Expr\FuncCall && str_contains($stmt->expr->expr->name->toString(), 'wrapCallable')) {
                        $hasWrapCallable = true;
                    }
                }
            }
            expect($hasWrapCallable)->toBeTrue();

            $unionIterableParam = new Node\Param(
                new Node\Expr\Variable('items'),
                null,
                new Node\UnionType([
                    new Node\Name('Traversable'),
                    new Node\Name('Countable'),
                ])
            );

            $methodIterableUnion = new Node\Stmt\ClassMethod('testUnionIterable', [
                'params' => [$unionIterableParam],
                'stmts' => [],
            ]);

            FunctionContractInjector::inject($methodIterableUnion);
            expect($methodIterableUnion->stmts)->not()->toBeEmpty();

            $hasWrapIterable = false;
            foreach ($methodIterableUnion->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof Node\Expr\Assign) {
                    if ($stmt->expr->expr instanceof Node\Expr\FuncCall && str_contains($stmt->expr->expr->name->toString(), 'wrapIterable')) {
                        $hasWrapIterable = true;
                    }
                }
            }
            expect($hasWrapIterable)->toBeTrue();
        });
    });

    test('skips return contract injection when @return specifies mixed', function () {
        $doc = new Doc('/** @return mixed */');
        $fnMixed = new Node\Stmt\Function_('fnMixed', [
            'stmts' => [new Node\Stmt\Return_(new Node\Scalar\String_('ok'))],
        ], [
            'comments' => [$doc],
        ]);

        FunctionContractInjector::inject($fnMixed);

        expect($fnMixed->stmts[0]->expr)->toBeInstanceOf(Node\Scalar\String_::class);
    });

    test('bypasses already wrapped yield from and var wrapped return nodes', function () {
        $yieldFrom = new Node\Expr\YieldFrom(new Node\Expr\Array_());
        $yieldFrom->setAttribute('typephp_wrapped', true);

        $docGen = new Doc('/** @return Generator<string, int> */');
        $fnGen = new Node\Stmt\Function_('fnGenPreWrapped', [
            'stmts' => [new Node\Stmt\Expression($yieldFrom)],
        ], [
            'comments' => [$docGen],
        ]);

        FunctionContractInjector::inject($fnGen);
        expect($fnGen->stmts)->not()->toBeEmpty();

        $ret = new Node\Stmt\Return_(new Node\Scalar\String_('already_wrapped'));
        $ret->setAttribute('typephp_var_wrapped', true);

        $docRet = new Doc('/** @return string */');
        $fnRet = new Node\Stmt\Function_('fnRetVarWrapped', [
            'stmts' => [$ret],
        ], [
            'comments' => [$docRet],
        ]);

        FunctionContractInjector::inject($fnRet);
        expect($fnRet->stmts[0]->expr)->toBeInstanceOf(Node\Scalar\String_::class);
    });
});
