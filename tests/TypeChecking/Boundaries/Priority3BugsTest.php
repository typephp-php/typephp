<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

require_once __DIR__ . '/../../Fixtures/Priority3/MultiNamespaceFixtures.php';
require_once __DIR__ . '/../../Fixtures/Priority3/CallableAliasFixtures.php';
require_once __DIR__ . '/../../Fixtures/Priority3/ArrayAccessSubclassFixtures.php';

use ArrayIterator;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use ReflectionMethod;
use stdClass;
use TypePHP\Exception\TypeError;
use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\RuntimeTypeChecker;
use TypePHP\Internal\Util\Config;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Priority3\Priority3ArrayAccessHolder;
use TypePHP\Tests\Fixtures\Priority3\Priority3CallableAliasHolder;
use TypePHP\Tests\Fixtures\Priority3\SubA\MultiNsCallerA;
use TypePHP\Tests\Fixtures\Priority3\SubB\MultiNsCallerB;

class Priority3NullableReturnFixture
{
    /**
     * @return int|null
     */
    public function getNullableInt(mixed $val): mixed
    {
        return $val;
    }

    /**
     * @return string|null
     */
    public function getNullableString(mixed $val): mixed
    {
        return $val;
    }
}

describe('Priority 3 Bugs: Cross-Namespace Static Elimination, Aliased Callables & Opcode Fast-Paths (Red Phase)', function () {
    beforeEach(function () {
        Config::reset();
    });

    afterEach(function () {
        Config::reset();
    });

    describe('Bug 6: Multi-Namespace Static Elimination Namespace Scoping', function () {
        test('eliminates valid checks and preserves invalid checks per namespace without cross-contamination', function () {
            $fixturePath = realpath(__DIR__ . '/../../Fixtures/Priority3/MultiNamespaceFixtures.php');
            expect($fixturePath)->not()->toBeFalse();

            $source = file_get_contents((string) $fixturePath);
            expect($source)->not()->toBeFalse();

            $transformed = StreamWrapper::transformSource((string) $source, (string) $fixturePath);

            [$subAPart, $subBPart] = explode('namespace TypePHP\Tests\Fixtures\Priority3\SubB', $transformed, 2);
            [$subAGetPet, $subARest] = explode('function getWrongPet', $subAPart, 2);
            [$subAGetWrongPet, $subAPassthroughRest] = explode('function passthroughDog', $subARest, 2);
            [$subAPassthroughDog, $subAUnsoundPassthrough] = explode('function unsoundPassthrough', $subAPassthroughRest, 2);

            expect($subAGetPet)->not()->toContain('RuntimeTypeChecker::checkReturn')
                ->and($subAGetWrongPet)->toContain('RuntimeTypeChecker::checkReturn')
                ->and($subAPassthroughDog)->not()->toContain('RuntimeTypeChecker::checkReturn')
                ->and($subAUnsoundPassthrough)->toContain('RuntimeTypeChecker::checkReturn')
                ->and($subBPart)->not()->toContain('RuntimeTypeChecker::checkReturn')
            ;

            $callerA = new MultiNsCallerA();
            $callerB = new MultiNsCallerB();

            expect($callerA->getPet())->toBeInstanceOf(Dog::class)
                ->and($callerA->passthroughDog(new Dog()))->toBeInstanceOf(Dog::class)
                ->and($callerB->getPet())->toBeInstanceOf(Cat::class)
                ->and(fn () => $callerA->getWrongPet())->toThrow(TypeError::class, 'Dog')
                ->and(fn () => $callerA->unsoundPassthrough(new Dog()))->toThrow(TypeError::class, 'Cat')
            ;
        });
    });

    describe('Bug 7: Aliased Callables/Iterables & Concrete ArrayAccess Subclasses', function () {
        test('injects wrapCallable when parameter uses @phpstan-type alias for a callable', function () {
            $fixturePath = realpath(__DIR__ . '/../../Fixtures/Priority3/CallableAliasFixtures.php');
            $source = file_get_contents((string) $fixturePath);
            $transformed = StreamWrapper::transformSource((string) $source, (string) $fixturePath);

            expect($transformed)->toContain('RuntimeTypeChecker::wrapCallable');

            $holder = new Priority3CallableAliasHolder();
            $badCb = fn (int $n): string => 'not_a_bool';

            expect(fn () => $holder->filterNumber($badCb, 10))
                ->toThrow(TypeError::class, 'Callback $predicate return value must be of type bool')
            ;
        });

        test('injects wrapCallable and validates argument constraint through @phpstan-type alias', function () {
            $holder = new Priority3CallableAliasHolder();
            $cb = fn (int $n): bool => true;

            expect(fn () => $holder->filterNumber($cb, -5))
                ->toThrow(TypeError::class, 'positive-int')
                ->and($holder->filterOptionalNumber(null, -5))->toBeTrue()
                ->and(fn () => $holder->filterOptionalNumber($cb, -5))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        });

        test('injects wrapIterable when parameter uses @phpstan-type alias for an iterable', function () {
            $fixturePath = realpath(__DIR__ . '/../../Fixtures/Priority3/CallableAliasFixtures.php');
            $source = file_get_contents((string) $fixturePath);
            $transformed = StreamWrapper::transformSource((string) $source, (string) $fixturePath);

            expect($transformed)->toContain('RuntimeTypeChecker::wrapIterable');

            $holder = new Priority3CallableAliasHolder();
            $badIterator = new ArrayIterator([10, -50]);

            expect(fn () => $holder->countStrings($badIterator))
                ->toThrow(TypeError::class, 'must be of type string')
            ;
        });

        test('validates member dimension assignment on concrete and partially-generic ArrayAccess subclasses', function () {
            $holder = new Priority3ArrayAccessHolder();

            $holder->map['b'] = 20;
            expect($holder->map['b'])->toBe(20);

            expect(fn () => $holder->map['b'] = 'not_an_int')
                ->toThrow(TypeError::class, 'must be of type int')
                ->and(fn () => $holder->map[123] = 20)
                ->toThrow(TypeError::class, 'must be of type string')
            ;

            $holder->genericMap['b'] = 30;
            expect($holder->genericMap['b'])->toBe(30);

            expect(fn () => $holder->genericMap['b'] = 'not_an_int')
                ->toThrow(TypeError::class, 'must be of type int')
                ->and(fn () => $holder->genericMap[123] = 30)
                ->toThrow(TypeError::class, 'must be of type string')
            ;

            $holder->mutateLocalMap('ok', 40);

            expect(fn () => $holder->mutateLocalMap('ok', 'bad'))
                ->toThrow(TypeError::class, 'must be of type int')
                ->and(fn () => $holder->mutateLocalMap(123, 40))
                ->toThrow(TypeError::class, 'must be of type string')
            ;
        });
    });

    describe('Bug 8: Fast-Path Return Opcode Compilation for 2-Member Nullable Unions (T|null)', function () {
        test('compiles fast opcode without falling back to full registry dispatch for 2-member nullable unions', function () {
            $ref = new ReflectionMethod(RuntimeTypeChecker::class, 'compileSimpleReturnOp');

            $cases = [
                new UnionTypeNode([new IdentifierTypeNode('int'), new IdentifierTypeNode('null')]),
                new UnionTypeNode([new IdentifierTypeNode('null'), new IdentifierTypeNode('int')]),
                new UnionTypeNode([new IdentifierTypeNode('string'), new IdentifierTypeNode('null')]),
                new UnionTypeNode([new IdentifierTypeNode('null'), new IdentifierTypeNode('string')]),
                new UnionTypeNode([new IdentifierTypeNode('bool'), new IdentifierTypeNode('null')]),
                new UnionTypeNode([new IdentifierTypeNode('float'), new IdentifierTypeNode('null')]),
                new UnionTypeNode([new IdentifierTypeNode('array'), new IdentifierTypeNode('null')]),
                new UnionTypeNode([new IdentifierTypeNode('positive-int'), new IdentifierTypeNode('null')]),
                new UnionTypeNode([new IdentifierTypeNode(stdClass::class), new IdentifierTypeNode('null')]),
            ];

            foreach ($cases as $unionNode) {
                $res = $ref->invoke(null, $unionNode);
                expect($res[0])->not()->toBe(0);
            }

            $nonNullableUnion = new UnionTypeNode([new IdentifierTypeNode('int'), new IdentifierTypeNode('string')]);
            $fallbackRes = $ref->invoke(null, $nonNullableUnion);
            expect($fallbackRes[0])->toBe(0);
        });

        test('validates valid, null, and invalid return values cleanly via compiled T|null return plans', function () {
            $fixture = new Priority3NullableReturnFixture();

            expect($fixture->getNullableInt(42))->toBe(42)
                ->and($fixture->getNullableInt(99))->toBe(99)
                ->and($fixture->getNullableInt(null))->toBeNull()
                ->and($fixture->getNullableString('hello'))->toBe('hello')
                ->and($fixture->getNullableString('world'))->toBe('world')
                ->and($fixture->getNullableString(null))->toBeNull()
                ->and(fn () => $fixture->getNullableInt('bad'))->toThrow(TypeError::class, 'must be of type (int | null)')
                ->and(fn () => $fixture->getNullableString(123))->toThrow(TypeError::class, 'must be of type (string | null)')
            ;
        });
    });
});
