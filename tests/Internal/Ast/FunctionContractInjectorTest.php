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
});
