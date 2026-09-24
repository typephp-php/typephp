<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Wrapper;

use Closure;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeParameterNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use ReflectionClass;
use TypeError;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Resolver\CallerBoundaryResolver;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Validator\TypeValidatorRegistry;
use TypePHP\Internal\Wrapper\CallableWrapper;

/**
 * @phpstan-type FormatterAlias callable(positive-int): non-empty-string
 *
 * @param FormatterAlias $formatter
 */
function fixtureAliasCallableFunction(callable $formatter): void
{
}

/**
 * @param (callable(positive-int): non-empty-string)[] $formatters
 */
function fixtureArrayCallableFunction(array $formatters): void
{
}

/**
 * @return callable(positive-int): non-empty-string
 */
function fixtureReturnCallableFunction(): callable
{
    return fn (int $x): string => "id_{$x}";
}

/**
 * @param list<callable(positive-int): string> $listCallbacks
 * @param array<string, callable(positive-int): string> $mapCallbacks
 */
function fixtureGenericCollectionCallables(array $listCallbacks, array $mapCallbacks): void
{
}

describe('CallableWrapper Unit Tests', function () {
    afterEach(function () {
        CallerBoundaryResolver::reset();
        Config::reset();
    });

    describe('wrap() Pipeline & Collection Wrapping', function () {
        test('returns raw value if callable is not valid or node is not CallableTypeNode', function () {
            $registry = new TypeValidatorRegistry();
            $notCallable = 'not_a_callable_string_xyz';

            $result = CallableWrapper::wrapTypeNode(null, $notCallable, 'prefix', $registry);

            expect($result)->toBe($notCallable);
        });

        test('resolves type alias pointing to a callable', function () {
            $registry = new TypeValidatorRegistry();
            $fn = fn (int $id): string => "user_{$id}";

            $callableNode = new CallableTypeNode(
                new IdentifierTypeNode('callable'),
                [new CallableTypeParameterNode(new IdentifierTypeNode('positive-int'), false, false, 'id', false)],
                new IdentifierTypeNode('string'),
                []
            );

            $ref = new ReflectionClass(DocblockParser::class);
            $prop = $ref->getProperty('cache');
            $cache = $prop->getValue();
            $cache['testUnexpandedAliasFunc'] = [
                'types' => ['formatter' => new IdentifierTypeNode('UnexpandedCallableAlias')],
                'return' => null,
                'aliases' => ['UnexpandedCallableAlias' => $callableNode],
                'templates' => [],
                'classTemplates' => [],
            ];
            $prop->setValue(null, $cache);

            try {
                $wrapped = CallableWrapper::wrap('testUnexpandedAliasFunc', 'formatter', $fn, $registry);

                expect($wrapped(10))->toBe('user_10');
                expect(fn () => $wrapped(-5))->toThrow(TypeError::class, 'positive-int');
            } finally {
                DocblockParser::reset();
            }
        });

        test('wraps callable arrays with ArrayTypeNode and preserves non-callable items', function () {
            $registry = new TypeValidatorRegistry();
            $fn = fn (int $id): string => "item_{$id}";

            $items = [
                'first' => $fn,
                'second' => 'not_a_callable_string',
            ];

            $wrapped = CallableWrapper::wrap(
                __NAMESPACE__ . '\fixtureArrayCallableFunction',
                'formatters',
                $items,
                $registry
            );

            expect($wrapped['first'](20))->toBe('item_20')
                ->and($wrapped['second'])->toBe('not_a_callable_string')
            ;

            expect(fn () => $wrapped['first'](-1))->toThrow(TypeError::class, 'positive-int');
        });

        test('wraps generic lists and maps of callables', function () {
            $registry = new TypeValidatorRegistry();
            $fn = fn (int $x): string => "num_{$x}";

            $wrappedList = CallableWrapper::wrap(
                __NAMESPACE__ . '\fixtureGenericCollectionCallables',
                'listCallbacks',
                [$fn],
                $registry
            );
            expect($wrappedList[0](5))->toBe('num_5');

            $wrappedMap = CallableWrapper::wrap(
                __NAMESPACE__ . '\fixtureGenericCollectionCallables',
                'mapCallbacks',
                ['action' => $fn],
                $registry
            );
            expect($wrappedMap['action'](10))->toBe('num_10');
        });

        test('returns raw non-callable value when passed to wrap()', function () {
            $registry = new TypeValidatorRegistry();
            $res = CallableWrapper::wrap(
                __NAMESPACE__ . '\fixtureAliasCallableFunction',
                'formatter',
                12345,
                $registry
            );

            expect($res)->toBe(12345);
        });

        test('wraps return callable contracts', function () {
            $registry = new TypeValidatorRegistry();
            $fn = fn (int $id): string => "ret_{$id}";

            $wrapped = CallableWrapper::wrap(
                __NAMESPACE__ . '\fixtureReturnCallableFunction',
                'return',
                $fn,
                $registry
            );

            expect($wrapped(42))->toBe('ret_42');
        });
    });

    describe('createDispatcherClosure() By-Reference Signatures & Error Trapping', function () {
        test('wraps callable and validates argument types on invocation', function () {
            $registry = new TypeValidatorRegistry();
            $callable = fn (int $id): string => "id_{$id}";

            $typeNode = new CallableTypeNode(
                new IdentifierTypeNode('callable'),
                [
                    new CallableTypeParameterNode(
                        new IdentifierTypeNode('positive-int'),
                        false,
                        false,
                        'id',
                        false
                    ),
                ],
                new IdentifierTypeNode('string'),
                []
            );

            $wrapped = CallableWrapper::wrapTypeNode($typeNode, $callable, 'TestCallback', $registry);

            expect($wrapped(10))->toBe('id_10');

            expect(fn () => $wrapped(-5))
                ->toThrow(TypeError::class, 'TestCallback $id must be of type positive-int')
            ;
        });

        test('catches native TypeError in [true] single by-ref parameter dispatcher', function () {
            $registry = new TypeValidatorRegistry();
            $throwing = function (int &$num): void {
                throw new TypeError('Native type error in single ref');
            };

            $typeNode = new CallableTypeNode(
                new IdentifierTypeNode('callable'),
                [new CallableTypeParameterNode(new IdentifierTypeNode('positive-int'), true, false, 'num', false)],
                new IdentifierTypeNode('void'),
                []
            );

            $wrapped = CallableWrapper::wrapTypeNode($typeNode, $throwing, 'TestRefSingle', $registry);
            $val = 10;

            expect(fn () => $wrapped($val))->toThrow(TypeError::class, 'Native type error in single ref');
        });

        test('catches native TypeError in [true, false] mixed parameter dispatcher', function () {
            $registry = new TypeValidatorRegistry();
            $throwing = function (int &$a, string $b): void {
                throw new TypeError('Native error in [true, false]');
            };

            $typeNode = new CallableTypeNode(
                new IdentifierTypeNode('callable'),
                [
                    new CallableTypeParameterNode(new IdentifierTypeNode('positive-int'), true, false, 'a', false),
                    new CallableTypeParameterNode(new IdentifierTypeNode('string'), false, false, 'b', false),
                ],
                new IdentifierTypeNode('void'),
                []
            );

            $wrapped = CallableWrapper::wrapTypeNode($typeNode, $throwing, 'TestTrueFalse', $registry);
            $val = 10;

            expect(fn () => $wrapped($val, 'test'))->toThrow(TypeError::class, 'Native error in [true, false]');
        });

        test('catches native TypeError in [false, true] mixed parameter dispatcher', function () {
            $registry = new TypeValidatorRegistry();
            $throwing = function (string $a, int &$b): void {
                throw new TypeError('Native error in [false, true]');
            };

            $typeNode = new CallableTypeNode(
                new IdentifierTypeNode('callable'),
                [
                    new CallableTypeParameterNode(new IdentifierTypeNode('string'), false, false, 'a', false),
                    new CallableTypeParameterNode(new IdentifierTypeNode('positive-int'), true, false, 'b', false),
                ],
                new IdentifierTypeNode('void'),
                []
            );

            $wrapped = CallableWrapper::wrapTypeNode($typeNode, $throwing, 'TestFalseTrue', $registry);
            $val = 10;

            expect(fn () => $wrapped('test', $val))->toThrow(TypeError::class, 'Native error in [false, true]');
        });

        test('executes and catches native TypeError in [true, true] dual by-ref dispatcher', function () {
            $registry = new TypeValidatorRegistry();
            $mutator = function (int &$a, int &$b): void {
                $a += 10;
                $b += 20;
            };

            $typeNode = new CallableTypeNode(
                new IdentifierTypeNode('callable'),
                [
                    new CallableTypeParameterNode(new IdentifierTypeNode('positive-int'), true, false, 'a', false),
                    new CallableTypeParameterNode(new IdentifierTypeNode('positive-int'), true, false, 'b', false),
                ],
                new IdentifierTypeNode('void'),
                []
            );

            $wrapped = CallableWrapper::wrapTypeNode($typeNode, $mutator, 'TestDualRef', $registry);
            $x = 5;
            $y = 15;
            $wrapped($x, $y);

            expect($x)->toBe(15)->and($y)->toBe(35);

            $throwing = function (int &$a, int &$b): void {
                throw new TypeError('Native error in dual ref');
            };
            $wrappedThrow = CallableWrapper::wrapTypeNode($typeNode, $throwing, 'TestDualRef', $registry);
            expect(fn () => $wrappedThrow($x, $y))->toThrow(TypeError::class, 'Native error in dual ref');
        });

        test('catches native TypeError in variadic by-ref dispatcher', function () {
            $registry = new TypeValidatorRegistry();
            $throwing = function (int &...$numbers): void {
                throw new TypeError('Native error in variadic ref');
            };

            $typeNode = new CallableTypeNode(
                new IdentifierTypeNode('callable'),
                [new CallableTypeParameterNode(new IdentifierTypeNode('positive-int'), true, true, 'numbers', false)],
                new IdentifierTypeNode('void'),
                []
            );

            $wrapped = CallableWrapper::wrapTypeNode($typeNode, $throwing, 'TestVarRef', $registry);
            $a = 10;

            expect(fn () => $wrapped($a))->toThrow(TypeError::class, 'Native error in variadic ref');
        });

        test('executes and catches native TypeError in fallback 3+ parameter dispatcher', function () {
            $registry = new TypeValidatorRegistry();
            $threeParams = function (int &$a, string $b, int &$c): void {
                $a += 1;
                $c += 2;
            };

            $typeNode = new CallableTypeNode(
                new IdentifierTypeNode('callable'),
                [
                    new CallableTypeParameterNode(new IdentifierTypeNode('positive-int'), true, false, 'a', false),
                    new CallableTypeParameterNode(new IdentifierTypeNode('string'), false, false, 'b', false),
                    new CallableTypeParameterNode(new IdentifierTypeNode('positive-int'), true, false, 'c', false),
                ],
                new IdentifierTypeNode('void'),
                []
            );

            $wrapped = CallableWrapper::wrapTypeNode($typeNode, $threeParams, 'TestFallbackRef', $registry);
            $x = 10;
            $msg = 'msg';
            $z = 30;
            $wrapped($x, $msg, $z);

            expect($x)->toBe(11)->and($z)->toBe(32);

            $throwing = function (int &$a, string $b, int &$c): void {
                throw new TypeError('Native error in fallback ref');
            };
            $wrappedThrow = CallableWrapper::wrapTypeNode($typeNode, $throwing, 'TestFallbackRef', $registry);
            expect(fn () => $wrappedThrow($x, $msg, $z))->toThrow(TypeError::class, 'Native error in fallback ref');
        });
    });

    describe('Variadic & Named Parameter Validations & Errors', function () {
        test('throws TypeError on non-vendor variadic argument type error', function () {
            $registry = new TypeValidatorRegistry();
            $typeNode = new CallableTypeNode(
                new IdentifierTypeNode('callable'),
                [new CallableTypeParameterNode(new IdentifierTypeNode('positive-int'), false, true, 'numbers', false)],
                new IdentifierTypeNode('void'),
                []
            );

            $wrapped = CallableWrapper::wrapTypeNode($typeNode, fn (...$nums) => null, 'TestVarArgErr', $registry);

            expect(fn () => $wrapped(10, -5))->toThrow(TypeError::class, 'variadic argument #2');
        });

        test('throws TypeError on non-vendor variadic by-ref mutation error', function () {
            $registry = new TypeValidatorRegistry();
            $typeNode = new CallableTypeNode(
                new IdentifierTypeNode('callable'),
                [new CallableTypeParameterNode(new IdentifierTypeNode('positive-int'), true, true, 'numbers', false)],
                new IdentifierTypeNode('void'),
                []
            );

            $badMutator = function (int &...$numbers): void {
                $numbers[0] = -100;
            };

            $wrapped = CallableWrapper::wrapTypeNode($typeNode, $badMutator, 'TestVarMutErr', $registry);
            $val = 10;

            expect(fn () => $wrapped($val))->toThrow(TypeError::class, 'positive-int');
        });

        test('validates by-ref parameter mutations when arguments are passed by name', function () {
            $registry = new TypeValidatorRegistry();
            $threeParams = function (int &$first, string $label, int &$second): void {
                $first += 10;
                $second += 20;
            };

            $typeNode = new CallableTypeNode(
                new IdentifierTypeNode('callable'),
                [
                    new CallableTypeParameterNode(new IdentifierTypeNode('positive-int'), true, false, 'first', false),
                    new CallableTypeParameterNode(new IdentifierTypeNode('string'), false, false, 'label', false),
                    new CallableTypeParameterNode(new IdentifierTypeNode('positive-int'), true, false, 'second', false),
                ],
                new IdentifierTypeNode('void'),
                []
            );

            $wrapped = CallableWrapper::wrapTypeNode($typeNode, $threeParams, 'TestNamedRef', $registry);
            $x = 5;
            $lbl = 'hello';
            $y = 15;
            $wrapped(first: $x, label: $lbl, second: $y);

            expect($x)->toBe(15)->and($y)->toBe(35);
        });
    });

    describe('Vendor Boundary Bypasses in Callables', function () {
        test('bypasses closure constraints, argument checks, mutations, and return checks when caller is vendor', function () {
            $registry = new TypeValidatorRegistry();

            $tempBase = sys_get_temp_dir() . '/typephp_vendor_runner_' . uniqid();
            $vendorDir = $tempBase . '/vendor/acme/runner';
            mkdir($vendorDir, 0777, true);
            $runnerFile = $vendorDir . '/VendorCaller.php';

            $runnerCode = <<<'PHP'
<?php
namespace Acme\VendorRunner;

use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use TypePHP\Internal\Validator\TypeValidatorRegistry;
use TypePHP\Internal\Wrapper\CallableWrapper;

class VendorCaller
{
    public static function wrapInVendor(CallableTypeNode $typeNode, mixed $callable, string $prefix, TypeValidatorRegistry $registry): mixed
    {
        $action = function () use ($typeNode, $callable, $prefix, $registry) {
            return CallableWrapper::wrapTypeNode($typeNode, $callable, $prefix, $registry);
        };

        return $action();
    }

    public static function createBoundClosure(): \Closure
    {
        $instance = new self();
        return (function () { return $this; })->bindTo($instance, self::class);
    }

    public static function createBadCallback(): \Closure
    {
        return function (int $x): int { return -999; };
    }

    public static function createMutator(): \Closure
    {
        return function (int &$a, int &...$vars): void {
            $a = -10;
            $vars[0] = -20;
        };
    }

    public static function createVariadicBadCallback(): \Closure
    {
        return function (int ...$nums): void {};
    }
}
PHP;
            file_put_contents($runnerFile, $runnerCode);

            try {
                require_once $runnerFile;

                $typeClosure = new CallableTypeNode(new IdentifierTypeNode('Closure'), [], new IdentifierTypeNode('void'), []);
                $invokable = new class () {
                    public function __invoke(): void
                    {
                    }
                };

                $wrappedClosure = \Acme\VendorRunner\VendorCaller::wrapInVendor(
                    $typeClosure,
                    $invokable,
                    'TestVendorClosure',
                    $registry
                );
                expect($wrappedClosure)->toBeInstanceOf(Closure::class);

                $typeStaticClosure = new CallableTypeNode(new IdentifierTypeNode('static-closure'), [], new IdentifierTypeNode('void'), []);
                $boundClosure = \Acme\VendorRunner\VendorCaller::createBoundClosure();
                $wrappedStatic = CallableWrapper::wrapTypeNode($typeStaticClosure, $boundClosure, 'TestVendorStatic', $registry);
                expect($wrappedStatic)->toBeInstanceOf(Closure::class);

                $typeWithContracts = new CallableTypeNode(
                    new IdentifierTypeNode('callable'),
                    [new CallableTypeParameterNode(new IdentifierTypeNode('positive-int'), false, false, 'id', false)],
                    new IdentifierTypeNode('positive-int'),
                    []
                );

                $badCallback = \Acme\VendorRunner\VendorCaller::createBadCallback();
                $wrapped = CallableWrapper::wrapTypeNode($typeWithContracts, $badCallback, 'TestVendorCb', $registry);

                $res = $wrapped(-5);
                expect($res)->toBe(-999);

                $typeVariadic = new CallableTypeNode(
                    new IdentifierTypeNode('callable'),
                    [new CallableTypeParameterNode(new IdentifierTypeNode('positive-int'), false, true, 'numbers', false)],
                    new IdentifierTypeNode('void'),
                    []
                );
                $badVarCallback = \Acme\VendorRunner\VendorCaller::createVariadicBadCallback();
                $wrappedVar = CallableWrapper::wrapTypeNode($typeVariadic, $badVarCallback, 'TestVendorVar', $registry);
                $wrappedVar(10, -5);

                $typeByRef = new CallableTypeNode(
                    new IdentifierTypeNode('callable'),
                    [
                        new CallableTypeParameterNode(new IdentifierTypeNode('positive-int'), true, false, 'ref', false),
                        new CallableTypeParameterNode(new IdentifierTypeNode('positive-int'), true, true, 'vars', false),
                    ],
                    new IdentifierTypeNode('void'),
                    []
                );

                $mutator = \Acme\VendorRunner\VendorCaller::createMutator();
                $wrappedMutator = CallableWrapper::wrapTypeNode($typeByRef, $mutator, 'TestVendorMut', $registry);

                $val = 5;
                $var1 = 10;
                $wrappedMutator($val, $var1);
                expect($val)->toBe(-10);
            } finally {
                @unlink($runnerFile);
                @rmdir($vendorDir);
                @rmdir($tempBase . '/vendor/acme');
                @rmdir($tempBase . '/vendor');
                @rmdir($tempBase);
                CallerBoundaryResolver::reset();
            }
        });
    });

    describe('Existing Helper Validations', function () {
        test('throws TypeError when non-closure is passed to Closure type contract in non-vendor code', function () {
            $registry = new TypeValidatorRegistry();
            $typeClosure = new CallableTypeNode(new IdentifierTypeNode('Closure'), [], new IdentifierTypeNode('void'), []);
            $invokable = new class () {
                public function __invoke(): void
                {
                }
            };

            expect(fn () => CallableWrapper::wrapTypeNode($typeClosure, $invokable, 'TestNonClosure', $registry))
                ->toThrow(TypeError::class, 'TestNonClosure must be of type Closure')
            ;
        });

        test('enforces static-closure constraints', function () {
            $registry = new TypeValidatorRegistry();
            $typeNode = new CallableTypeNode(
                new IdentifierTypeNode('static-closure'),
                [],
                new IdentifierTypeNode('void'),
                []
            );

            $nonStatic = fn () => null;

            expect(fn () => CallableWrapper::wrapTypeNode($typeNode, $nonStatic, 'TestStatic', $registry))
                ->toThrow(TypeError::class, 'must be a static Closure')
            ;
        });

        test('preserves by-reference parameter mutations in wrapped callback', function () {
            $registry = new TypeValidatorRegistry();
            $callable = function (int &$num): void {
                $num += 50;
            };

            $typeNode = new CallableTypeNode(
                new IdentifierTypeNode('callable'),
                [
                    new CallableTypeParameterNode(
                        new IdentifierTypeNode('positive-int'),
                        true,
                        false,
                        'num',
                        false
                    ),
                ],
                new IdentifierTypeNode('void'),
                []
            );

            $wrapped = CallableWrapper::wrapTypeNode($typeNode, $callable, 'TestByRef', $registry);

            $val = 10;
            $wrapped($val);
            expect($val)->toBe(60);

            $badVal = -5;
            expect(fn () => $wrapped($badVal))
                ->toThrow(TypeError::class, 'TestByRef $num must be of type positive-int')
            ;

            $badMutator = function (int &$num): void {
                $num = -100;
            };
            $wrappedBad = CallableWrapper::wrapTypeNode($typeNode, $badMutator, 'TestByRefBad', $registry);
            $val2 = 10;
            expect(fn () => $wrappedBad($val2))
                ->toThrow(TypeError::class, 'TestByRefBad $num must be of type positive-int')
            ;
        });

        test('handles mixed by-ref and by-value parameters in wrapped callback', function () {
            $registry = new TypeValidatorRegistry();
            $callable = function (int &$item, string $key): void {
                $item *= 2;
            };

            $typeNode = new CallableTypeNode(
                new IdentifierTypeNode('callable'),
                [
                    new CallableTypeParameterNode(new IdentifierTypeNode('positive-int'), true, false, 'item', false),
                    new CallableTypeParameterNode(new IdentifierTypeNode('string'), false, false, 'key', false),
                ],
                new IdentifierTypeNode('void'),
                []
            );

            $wrapped = CallableWrapper::wrapTypeNode($typeNode, $callable, 'TestMixed', $registry);

            $num = 25;
            $wrapped($num, 'key_1');
            expect($num)->toBe(50);
        });

        test('preserves variadic by-reference mutations in wrapped callback', function () {
            $registry = new TypeValidatorRegistry();
            $callable = function (int &...$numbers): void {
                foreach ($numbers as &$n) {
                    $n += 5;
                }
            };

            $typeNode = new CallableTypeNode(
                new IdentifierTypeNode('callable'),
                [
                    new CallableTypeParameterNode(new IdentifierTypeNode('positive-int'), true, true, 'numbers', false),
                ],
                new IdentifierTypeNode('void'),
                []
            );

            $wrapped = CallableWrapper::wrapTypeNode($typeNode, $callable, 'TestVariadicRef', $registry);

            $a = 10;
            $b = 20;
            $wrapped($a, $b);
            expect($a)->toBe(15)
                ->and($b)->toBe(25)
            ;
        });

        test('isCallable helper accurately identifies callables and rejects unsafe deprecated relative strings', function () {
            expect(CallableWrapper::isCallable('strlen'))->toBeTrue();
            expect(CallableWrapper::isCallable(fn () => 1))->toBeTrue();
            expect(CallableWrapper::isCallable(new class () {
                public function __invoke(): void
                {
                }
            }))->toBeTrue();

            expect(CallableWrapper::isCallable(''))->toBeFalse();
            expect(CallableWrapper::isCallable('static::method'))->toBeFalse();
            expect(CallableWrapper::isCallable(['static', 'method']))->toBeFalse();
            expect(CallableWrapper::isCallable(['self', 'method']))->toBeFalse();
            expect(CallableWrapper::isCallable(['parent', 'method']))->toBeFalse();
            expect(CallableWrapper::isCallable('not_callable_123'))->toBeFalse();
            expect(CallableWrapper::isCallable(123))->toBeFalse();
        });
    });
});
