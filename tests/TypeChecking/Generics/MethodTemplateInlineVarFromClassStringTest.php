<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;

class SimpleTestEntity
{
    public function __construct(public string $name = 'Test')
    {
    }
}

class AnotherTestEntity
{
    public function __construct(public string $name = 'Another')
    {
    }
}

class IncompatibleTestEntity
{
}

class ClassEntityFixture
{
    public function __construct(public string $role = 'admin')
    {
    }
}

class MethodEntityFixture
{
    public function __construct(public string $title = 'manager')
    {
    }
}

/**
 * Standard class without any class-level templates
 */
class ConfigArraySerializerFixture
{
    /**
     * Reproduces exact issue #6 from Symfony/Serializer pattern:
     * Method declares @template T of object, accepts class-string<T> $class,
     *
     * and assigns the denormalized value under /** @var T * /
     *
     * @template T of object
     *
     * @param class-string<T> $class
     * @param array<string, mixed> $data
     *
     * @return T
     */
    public function denormalize(string $class, array $data): object
    {
        /** @var T $object */
        $object = new $class($data['name'] ?? 'default');

        return $object;
    }

    /**
     * Assigns an object that violates the bound class-string<T>
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function denormalizeWithViolation(string $class): object
    {
        /** @var T $object */
        $object = new IncompatibleTestEntity();

        return $object;
    }
}

/**
 * Static method variant
 */
class StaticSerializerFixture
{
    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public static function create(string $class): object
    {
        /** @var T $instance */
        $instance = new $class();

        return $instance;
    }
}

/**
 * Combination 1: Class-level TClass AND method-level TMethod
 *
 * @template TClass of object
 */
class CombinedClassAndMethodTemplateFixture
{
    /**
     * @param TClass $classInstance
     */
    public function __construct(public object $classInstance)
    {
    }

    /**
     * Method with method-level template TMethod
     *
     * @template TMethod of object
     *
     * @param class-string<TMethod> $class
     *
     * @return array{class: TClass, method: TMethod}
     */
    public function combine(string $class): array
    {
        /** @var TClass $c */
        $c = $this->classInstance;

        /** @var TMethod $m */
        $m = new $class();

        /** @var array{class: TClass, method: TMethod} $bundle */
        $bundle = [
            'class' => $c,
            'method' => $m,
        ];

        return $bundle;
    }
}

/**
 * Combination 2: Class template T and method template T with the SAME name (shadowing)
 *
 * @template T of object
 */
class CombinedShadowedClassAndMethodTemplateFixture
{
    /**
     * @param T $instance
     */
    public function __construct(public object $instance)
    {
    }

    /**
     * Method template T shadows class template T
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function createMethodInstance(string $class): object
    {
        /** @var T $obj */
        $obj = new $class();

        return $obj;
    }
}

describe('Inline @var T bound from class-string<T> parameter', function () {
    test('binds method-level template T from class-string<T> parameter for inline @var T $object', function () {
        $serializer = new ConfigArraySerializerFixture();

        $result = $serializer->denormalize(SimpleTestEntity::class, ['name' => 'Sample']);

        expect($result)->toBeInstanceOf(SimpleTestEntity::class)
            ->and($result->name)->toBe('Sample')
        ;
    });

    test('binds method-level template T dynamically across different calls with different classes', function () {
        $serializer = new ConfigArraySerializerFixture();

        $first = $serializer->denormalize(SimpleTestEntity::class, ['name' => 'First']);
        expect($first)->toBeInstanceOf(SimpleTestEntity::class);

        $second = $serializer->denormalize(AnotherTestEntity::class, ['name' => 'Second']);
        expect($second)->toBeInstanceOf(AnotherTestEntity::class);
    });

    test('binds method-level template T on static methods for inline @var T', function () {
        $result = StaticSerializerFixture::create(SimpleTestEntity::class);

        expect($result)->toBeInstanceOf(SimpleTestEntity::class);
    });

    test('enforces bound template T on inline @var and rejects incompatible assignments', function () {
        $serializer = new ConfigArraySerializerFixture();

        expect(fn () => $serializer->denormalizeWithViolation(SimpleTestEntity::class))
            ->toThrow(
                TypeError::class,
                'Variable $object must be of type ' . SimpleTestEntity::class . ', ' . IncompatibleTestEntity::class . ' given'
            )
        ;
    });

    describe('Combination of Class-Level and Method-Level Templates for Inline @var', function () {
        test('resolves both TClass and TMethod in inline @var annotations in the same method', function () {
            $classObj = new ClassEntityFixture();
            $fixture = new CombinedClassAndMethodTemplateFixture($classObj);

            $result = $fixture->combine(MethodEntityFixture::class);

            expect($result['class'])->toBe($classObj)
                ->and($result['method'])->toBeInstanceOf(MethodEntityFixture::class)
            ;
        });

        test('method-level T shadows class-level T for inline @var while preserving class-level T outside', function () {
            $classObj = new ClassEntityFixture();
            $fixture = new CombinedShadowedClassAndMethodTemplateFixture($classObj);

            $methodObj = $fixture->createMethodInstance(MethodEntityFixture::class);

            expect($methodObj)->toBeInstanceOf(MethodEntityFixture::class)
                ->and($fixture->instance)->toBe($classObj)
            ;
        });
    });
});
