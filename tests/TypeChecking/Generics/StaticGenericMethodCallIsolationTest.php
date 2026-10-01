<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use ReflectionClass;
use TypePHP\Exception\TypeError;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;

class StaticUserFixture
{
    public function __construct(public string $name = 'Alice')
    {
    }
}

class StaticProductFixture
{
    public function __construct(public string $sku = 'SKU-001')
    {
    }
}

class StaticOrderFixture
{
    public function __construct(public int $id = 1)
    {
    }
}

/**
 * @template T
 */
class ClassLevelTemplateStaticBucket
{
    /**
     * @param T $item
     *
     * @return T
     */
    public static function store(mixed $item): mixed
    {
        return $item;
    }

    /**
     * @param T $first
     * @param T $second
     */
    public static function compareSame(mixed $first, mixed $second): bool
    {
        return true;
    }
}

class MethodLevelTemplateStaticBucket
{
    /**
     * @template T
     *
     * @param T $item
     *
     * @return T
     */
    public static function process(mixed $item): mixed
    {
        return $item;
    }

    /**
     * @template T
     *
     * @param T $a
     * @param T $b
     */
    public static function assertSameType(mixed $a, mixed $b): bool
    {
        return true;
    }
}

/**
 * @template T of \TypePHP\Tests\Fixtures\Domain\Animal
 */
class BoundedClassLevelStaticBucket
{
    /**
     * @param T $pet
     *
     * @return T
     */
    public static function registerPet(mixed $pet): mixed
    {
        return $pet;
    }
}

/**
 * Generic Trait with static method using template T
 *
 * @template T
 */
trait StaticGenericLogTrait
{
    /**
     * @param T $record
     *
     * @return T
     */
    public static function logRecord(mixed $record): mixed
    {
        return $record;
    }

    /**
     * @param T $a
     * @param T $b
     */
    public static function assertTraitPair(mixed $a, mixed $b): bool
    {
        return true;
    }
}

class ClassUsingStaticGenericTrait
{
    use StaticGenericLogTrait;
}

/**
 * @template T
 */
trait FirstStaticTrait
{
    /**
     * @param T $item
     *
     * @return T
     */
    public static function firstAction(mixed $item): mixed
    {
        return $item;
    }
}

/**
 * @template U
 */
trait SecondStaticTrait
{
    /**
     * @param U $item
     *
     * @return U
     */
    public static function secondAction(mixed $item): mixed
    {
        return $item;
    }
}

class MultiTraitStaticService
{
    use FirstStaticTrait;
    use SecondStaticTrait;
}

/**
 * Abstract class with static method using class-level template
 *
 * @template T
 */
abstract class AbstractStaticGenericService
{
    /**
     * @param T $payload
     *
     * @return T
     */
    public static function handlePayload(mixed $payload): mixed
    {
        return $payload;
    }
}

class ConcreteStaticGenericChild extends AbstractStaticGenericService
{
}

/**
 * @template T
 */
class GrandParentStaticGenericHolder
{
    /**
     * @param T $data
     *
     * @return T
     */
    public static function dispatch(mixed $data): mixed
    {
        return $data;
    }
}

class ParentStaticGenericHolder extends GrandParentStaticGenericHolder
{
}

class ChildStaticGenericHolder extends ParentStaticGenericHolder
{
}

/**
 * @template T
 */
abstract class ConfigurableStaticParent
{
    /**
     * @param T $item
     *
     * @return T
     */
    public static function save(mixed $item): mixed
    {
        return $item;
    }
}

/**
 * Concrete child class binding T to StaticUserFixture statically via @extends
 *
 * @extends ConfigurableStaticParent<StaticUserFixture>
 */
class ParameterizedStaticChild extends ConfigurableStaticParent
{
}

describe('Static Generic Method Call-Stack Isolation & Leak Prevention', function () {
    describe('1. Simulated Long-Running Process: Class-Level Templates on Static Methods', function () {
        test('does not leak template bindings across sequential static method calls (Simulated Requests 1 to 4)', function () {
            $req1 = ClassLevelTemplateStaticBucket::store(new StaticUserFixture('Bob'));
            expect($req1)->toBeInstanceOf(StaticUserFixture::class);

            $req2 = ClassLevelTemplateStaticBucket::store(new StaticProductFixture('SKU-2'));
            expect($req2)->toBeInstanceOf(StaticProductFixture::class);

            $req3 = ClassLevelTemplateStaticBucket::store(new StaticOrderFixture(100));
            expect($req3)->toBeInstanceOf(StaticOrderFixture::class);

            $req4 = ClassLevelTemplateStaticBucket::store(new StaticUserFixture('Alice'));
            expect($req4)->toBeInstanceOf(StaticUserFixture::class);
        });

        test('cleans up call-stack bindings in TemplateManager after static method execution', function () {
            ClassLevelTemplateStaticBucket::store(new StaticUserFixture('Bob'));

            $ref = new ReflectionClass(TemplateManager::class);
            $prop = $ref->getProperty('callStackBindings');
            $callStack = $prop->getValue();

            $targetKey = ClassLevelTemplateStaticBucket::class . '::store';
            $frames = $callStack[$targetKey] ?? [];

            expect($frames)->toBeEmpty();
        });
    });

    describe('2. Within-Call Parameter Consistency (Same Static Call)', function () {
        test('accepts identical types for multiple parameters of type T in the same static call', function () {
            $userA = new StaticUserFixture('UserA');
            $userB = new StaticUserFixture('UserB');

            expect(ClassLevelTemplateStaticBucket::compareSame($userA, $userB))->toBeTrue();
        });

        test('rejects conflicting types passed to multiple parameters of type T in the same static call', function () {
            $user = new StaticUserFixture('UserA');
            $product = new StaticProductFixture('SKU-100');

            expect(fn () => ClassLevelTemplateStaticBucket::compareSame($user, $product))
                ->toThrow(TypeError::class, 'StaticUserFixture')
            ;
        });
    });

    describe('3. Method-Level Templates on Static Methods (@template T on method)', function () {
        test('allows sequential calls with different types on method-level static templates without leakage', function () {
            $res1 = MethodLevelTemplateStaticBucket::process(new StaticUserFixture());
            expect($res1)->toBeInstanceOf(StaticUserFixture::class);

            $res2 = MethodLevelTemplateStaticBucket::process(new StaticProductFixture());
            expect($res2)->toBeInstanceOf(StaticProductFixture::class);

            $res3 = MethodLevelTemplateStaticBucket::process(42);
            expect($res3)->toBe(42);
        });

        test('strictly enforces consistency for multiple parameters within the same method-level static call', function () {
            expect(MethodLevelTemplateStaticBucket::assertSameType(10, 20))->toBeTrue();

            expect(fn () => MethodLevelTemplateStaticBucket::assertSameType(10, 'string'))
                ->toThrow(TypeError::class, 'int')
            ;
        });
    });

    describe('4. Bounded Class-Level Templates on Static Methods (@template T of Animal)', function () {
        test('accepts any valid subtype of Animal across sequential static calls', function () {
            $dog = BoundedClassLevelStaticBucket::registerPet(new Dog());
            expect($dog)->toBeInstanceOf(Dog::class);

            $cat = BoundedClassLevelStaticBucket::registerPet(new Cat());
            expect($cat)->toBeInstanceOf(Cat::class);
        });

        test('rejects non-Animal types on static method call', function () {
            expect(fn () => BoundedClassLevelStaticBucket::registerPet(new Car()))
                ->toThrow(TypeError::class, 'Animal')
            ;
        });
    });

    describe('5. Traits Providing Generic Static Methods', function () {
        test('does not leak generic bindings across sequential calls to static methods provided by traits', function () {
            $res1 = ClassUsingStaticGenericTrait::logRecord(new StaticUserFixture('User-Trait'));
            expect($res1)->toBeInstanceOf(StaticUserFixture::class);

            $res2 = ClassUsingStaticGenericTrait::logRecord(new StaticProductFixture('Product-Trait'));
            expect($res2)->toBeInstanceOf(StaticProductFixture::class);

            $res3 = ClassUsingStaticGenericTrait::logRecord(new StaticOrderFixture(777));
            expect($res3)->toBeInstanceOf(StaticOrderFixture::class);
        });

        test('enforces parameter consistency within the same call on trait static methods', function () {
            expect(ClassUsingStaticGenericTrait::assertTraitPair(100, 200))->toBeTrue();

            expect(fn () => ClassUsingStaticGenericTrait::assertTraitPair(100, 'mismatch'))
                ->toThrow(TypeError::class, 'int')
            ;
        });

        test('keeps multiple traits on the same class isolated without cross-contamination', function () {
            $resFirst = MultiTraitStaticService::firstAction(new StaticUserFixture());
            expect($resFirst)->toBeInstanceOf(StaticUserFixture::class);

            $resSecond = MultiTraitStaticService::secondAction(new StaticProductFixture());
            expect($resSecond)->toBeInstanceOf(StaticProductFixture::class);
        });
    });

    describe('6. Abstract Class Inheritance with Static Generic Methods', function () {
        test('does not leak generic bindings across sequential static calls on concrete child class', function () {
            $call1 = ConcreteStaticGenericChild::handlePayload(new StaticUserFixture());
            expect($call1)->toBeInstanceOf(StaticUserFixture::class);

            $call2 = ConcreteStaticGenericChild::handlePayload(new StaticProductFixture());
            expect($call2)->toBeInstanceOf(StaticProductFixture::class);

            $call3 = ConcreteStaticGenericChild::handlePayload(500);
            expect($call3)->toBe(500);
        });
    });

    describe('7. Multi-Level Deep Inheritance Hierarchy (GrandParent -> Parent -> Child)', function () {
        test('isolates static generic calls across 3 hierarchy levels', function () {
            $childRes1 = ChildStaticGenericHolder::dispatch(new StaticUserFixture());
            expect($childRes1)->toBeInstanceOf(StaticUserFixture::class);

            $childRes2 = ChildStaticGenericHolder::dispatch(new StaticProductFixture());
            expect($childRes2)->toBeInstanceOf(StaticProductFixture::class);

            $parentRes = ParentStaticGenericHolder::dispatch(new StaticOrderFixture(10));
            expect($parentRes)->toBeInstanceOf(StaticOrderFixture::class);

            $grandParentRes = GrandParentStaticGenericHolder::dispatch('raw_string');
            expect($grandParentRes)->toBe('raw_string');
        });
    });

    describe('8. Statically Parameterized Subclass (@extends Parent<ConcreteType>)', function () {
        test('enforces static @extends binding on child while keeping unparameterized parent isolated', function () {
            $user = new StaticUserFixture('Alice');
            $childResult = ParameterizedStaticChild::save($user);
            expect($childResult)->toBe($user);

            expect(fn () => ParameterizedStaticChild::save(new StaticProductFixture()))
                ->toThrow(TypeError::class)
            ;

            $parentProduct = new StaticProductFixture('Parent-Product');
            $parentResult = ConfigurableStaticParent::save($parentProduct);
            expect($parentResult)->toBe($parentProduct);
        });
    });
});
