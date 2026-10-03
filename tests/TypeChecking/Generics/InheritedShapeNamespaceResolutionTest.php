<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use ArrayIterator;
use Generator;
use Traversable;
use TypePHP\Exception\TypeError;
use TypePHP\Internal\Util\Config;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\User;
use TypePHP\TypePHP;

/**
 * @template T
 */
class InheritedShapeBaseBox
{
    /**
     * @param T $value
     */
    public function put(mixed $value): mixed
    {
        return $value;
    }
}

/**
 * @extends InheritedShapeBaseBox<array{user: User, car?: Car}>
 */
class InheritedArrayShapeUserBox extends InheritedShapeBaseBox
{
}

/**
 * @extends InheritedShapeBaseBox<object{user: User}>
 */
class InheritedObjectShapeUserBox extends InheritedShapeBaseBox
{
}

/**
 * @extends InheritedShapeBaseBox<callable(User): User>
 */
class InheritedCallableUserBox extends InheritedShapeBaseBox
{
}

/**
 * Extends BaseBox with an iterable containing an unqualified class name
 *
 * @extends InheritedShapeBaseBox<iterable<User>>
 */
class InheritedIterableUserBox extends InheritedShapeBaseBox
{
}

/**
 * Extends BaseBox with a Traversable containing an unqualified class name
 *
 * @extends InheritedShapeBaseBox<Traversable<string, User>>
 */
class InheritedTraversableUserBox extends InheritedShapeBaseBox
{
    /**
     * Consumes the Traversable so lazy iteration checks fire
     *
     * @param Traversable<string, User> $items
     *
     * @return list<User>
     */
    public function consume(Traversable $items): array
    {
        $collected = [];
        foreach ($items as $item) {
            $collected[] = $item;
        }

        return $collected;
    }
}

/**
 * Extends BaseBox with a Generator containing an unqualified class name
 *
 * @extends InheritedShapeBaseBox<Generator<int, User>>
 */
class InheritedGeneratorUserBox extends InheritedShapeBaseBox
{
    /**
     * @return Generator<int, User>
     */
    public function stream(bool $valid = true): Generator
    {
        yield 0 => $valid ? new User('Alice') : new Car();
    }
}

/**
 * Extends BaseBox with an array shape holding a nested iterable of unqualified User
 *
 * @extends InheritedShapeBaseBox<array{stream: iterable<User>}>
 */
class InheritedShapeWithIterableUserBox extends InheritedShapeBaseBox
{
}

/**
 * @template T
 */
interface InheritedShapeInterface
{
    /**
     * @param T $data
     */
    public function process(mixed $data): mixed;
}

/**
 * @implements InheritedShapeInterface<array{user: User, car?: Car}>
 */
class InheritedShapeInterfaceImplementation implements InheritedShapeInterface
{
    public function process(mixed $data): mixed
    {
        return $data;
    }
}

/**
 * @template T
 */
trait InheritedShapeTrait
{
    /**
     * @param T $record
     */
    public function logRecord(mixed $record): mixed
    {
        return $record;
    }
}

class InheritedShapeInlineTraitConsumer
{
    /**
     * @use InheritedShapeTrait<array{user: User}>
     */
    use InheritedShapeTrait;
}

/**
 * @use InheritedShapeTrait<array{user: User}>
 */
class InheritedShapeClassLevelTraitConsumer
{
    use InheritedShapeTrait;
}

describe('Inherited Generic Shapes & Iterables Namespace Resolution', function () {
    beforeEach(function () {
        Config::reset();
    });

    afterEach(function () {
        Config::reset();
    });

    describe('Class Shapes & Callables (@extends)', function () {
        test('resolves unqualified class names inside array shapes in @extends tag (reported bug)', function () {
            $box = new InheritedArrayShapeUserBox();

            $payload = ['user' => new User('Alice')];
            expect($box->put($payload))->toBe($payload);

            expect(fn () => $box->put(['user' => new Car()]))
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Domain\User')
            ;
        });

        test('resolves unqualified class names inside object shapes in @extends tag', function () {
            $box = new InheritedObjectShapeUserBox();

            $valid = (object)['user' => new User('Bob')];
            expect($box->put($valid))->toBe($valid);

            $invalid = (object)['user' => new Car()];
            expect(fn () => $box->put($invalid))
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Domain\User')
            ;
        });

        test('resolves unqualified class names inside callables in @extends tag', function () {
            $box = new InheritedCallableUserBox();

            $cb = fn (User $u): User => $u;
            $wrapped = $box->put($cb);

            expect($wrapped(new User('Charlie')))->toBeInstanceOf(User::class);
        });
    });

    describe('Iterables and Generators (@extends)', function () {
        test('resolves unqualified class names inside iterable<User> in @extends tag', function () {
            $box = new InheritedIterableUserBox();

            expect(TypePHP::getGenericType($box))->toBe('iterable<TypePHP\Tests\Fixtures\Domain\User>');

            $payload = [new User('Alice'), new User('Bob')];
            expect($box->put($payload))->toBe($payload);

            expect(fn () => $box->put([new Car()]))
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Domain\User')
            ;
        });

        test('resolves unqualified class names inside Traversable<string, User> in @extends tag and validates on iteration', function () {
            $box = new InheritedTraversableUserBox();

            expect(TypePHP::getGenericType($box))->toBe('Traversable<string, TypePHP\Tests\Fixtures\Domain\User>');

            $iter = new ArrayIterator(['first' => new User('Alice')]);
            expect($box->consume($iter))->toHaveCount(1);

            $badIter = new ArrayIterator(['first' => new Car()]);
            expect(fn () => $box->consume($badIter))
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Domain\User')
            ;
        });

        test('resolves unqualified class names inside Generator<int, User> in @extends tag and validates on yield', function () {
            $box = new InheritedGeneratorUserBox();

            expect(TypePHP::getGenericType($box))->toBe('Generator<int, TypePHP\Tests\Fixtures\Domain\User>');

            $validGen = $box->stream(true);
            expect($validGen->current())->toBeInstanceOf(User::class);

            $badGen = $box->stream(false);
            expect(fn () => $badGen->current())
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Domain\User')
            ;
        });

        test('resolves unqualified class names inside array shape containing nested iterable<User>', function () {
            $box = new InheritedShapeWithIterableUserBox();

            $valid = ['stream' => [new User('Alice'), new User('Bob')]];
            expect($box->put($valid))->toBe($valid);

            $invalid = ['stream' => [new Car()]];
            expect(fn () => $box->put($invalid))
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Domain\User')
            ;
        });
    });

    describe('Interface Implementation (@implements)', function () {
        test('resolves unqualified class names inside array shapes in @implements tag', function () {
            $service = new InheritedShapeInterfaceImplementation();

            $valid = ['user' => new User('Dave')];
            expect($service->process($valid))->toBe($valid);

            expect(fn () => $service->process(['user' => new Car()]))
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Domain\User')
            ;
        });
    });

    describe('Trait Usage (@use)', function () {
        test('resolves unqualified class names inside array shapes in inline trait use statement', function () {
            $consumer = new InheritedShapeInlineTraitConsumer();

            $valid = ['user' => new User('Eve')];
            expect($consumer->logRecord($valid))->toBe($valid);

            expect(fn () => $consumer->logRecord(['user' => new Car()]))
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Domain\User')
            ;
        });

        test('resolves unqualified class names inside array shapes in class-level trait use docblock', function () {
            $consumer = new InheritedShapeClassLevelTraitConsumer();

            $valid = ['user' => new User('Frank')];
            expect($consumer->logRecord($valid))->toBe($valid);

            expect(fn () => $consumer->logRecord(['user' => new Car()]))
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Domain\User')
            ;
        });
    });
});
