<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use BackedEnum;
use TypePHP\Exception\TypeError;

/**
 * @template T of array<array-key, mixed>
 */
abstract class KeyOfBoundBase
{
    /**
     * @template K of key-of<T>
     *
     * @param K $key
     */
    public function get(string $key): string
    {
        return $key;
    }

    /**
     * @template K of key-of<T>
     *
     * @param K $key
     *
     * @return K
     */
    public function echoKey(string $key, ?string $overrideReturn = null): string
    {
        return $overrideReturn ?? $key;
    }

    /**
     * @template K of key-of<T>
     *
     * @param K|null $key
     */
    public function getConfiguration(string $type, ?string $key = null): ?string
    {
        return $key;
    }

    /**
     * @template K of key-of<T>
     *
     * @param K $key
     *
     * @return T[K]
     */
    public function getFieldValue(string $key, mixed $value): mixed
    {
        return $value;
    }

    /**
     * @template K of key-of<T>
     *
     * @param K $key
     */
    public function getByAnyKey(int|string $key): int|string
    {
        return $key;
    }

    /**
     * @template V of value-of<T>
     *
     * @param V $value
     */
    public function acceptValue(mixed $value): mixed
    {
        return $value;
    }
}

/**
 * @extends KeyOfBoundBase<array{selectedMenu: string, readList: string}>
 */
final class KeyOfBoundChild extends KeyOfBoundBase
{
}

/**
 * @extends KeyOfBoundBase<array{id: int, name: string, enabled: bool}>
 */
final class KeyOfBoundMixedShapeChild extends KeyOfBoundBase
{
}

/**
 * @extends KeyOfBoundBase<array{10: string, 20: int}>
 */
final class KeyOfBoundIntShapeChild extends KeyOfBoundBase
{
}

/**
 * @extends KeyOfBoundBase<array<string, mixed>>
 */
final class KeyOfBoundStringMapChild extends KeyOfBoundBase
{
}

/**
 * @extends KeyOfBoundBase<array<int, string>>
 */
final class KeyOfBoundIntMapChild extends KeyOfBoundBase
{
}

/**
 * @extends KeyOfBoundBase<array{primary: 'red', secondary: 'blue'}>
 */
final class KeyOfBoundLiteralValuesChild extends KeyOfBoundBase
{
}

enum KeyOfBoundStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}

/**
 * @template E of BackedEnum
 */
abstract class KeyOfBoundEnumBase
{
    /**
     * @template K of key-of<E>
     *
     * @param K $caseName
     */
    public function byCaseName(string $caseName): string
    {
        return $caseName;
    }

    /**
     * @template V of value-of<E>
     *
     * @param V $caseValue
     */
    public function byCaseValue(string $caseValue): string
    {
        return $caseValue;
    }
}

/**
 * @extends KeyOfBoundEnumBase<KeyOfBoundStatus>
 */
final class KeyOfBoundStatusRepo extends KeyOfBoundEnumBase
{
}

/**
 * @template T of array<string, mixed>
 * @template K of key-of<T>
 *
 * @param T $data
 * @param K $key
 */
function keyOfBoundPick(array $data, string $key): mixed
{
    return $data[$key] ?? null;
}

describe('Cross-Referenced key-of<T> and value-of<T> Template Bounds (Issue #139)', function () {
    describe('1. Method template K of key-of<T> on @extends array shape', function () {
        test('accepts valid keys of the array shape bound to T', function () {
            $child = new KeyOfBoundChild();

            expect($child->get('selectedMenu'))->toBe('selectedMenu');
            expect($child->get('readList'))->toBe('readList');
        });

        test('rejects keys not present in the array shape bound to T', function () {
            $child = new KeyOfBoundChild();

            expect(fn () => $child->get('unknownKey'))
                ->toThrow(TypeError::class, 'Argument $key')
            ;
        });

        test('infers K as the literal key rather than widening to string', function () {
            $child = new KeyOfBoundChild();

            expect($child->echoKey('selectedMenu'))->toBe('selectedMenu');

            expect(fn () => $child->echoKey('selectedMenu', 'readList'))
                ->toThrow(TypeError::class, 'Return value')
            ;
        });
    });

    describe('2. Nullable K|null parameter (getConfiguration pattern)', function () {
        test('accepts null and valid keys', function () {
            $child = new KeyOfBoundChild();

            expect($child->getConfiguration('menu', null))->toBeNull();
            expect($child->getConfiguration('menu', 'selectedMenu'))->toBe('selectedMenu');
            expect($child->getConfiguration('menu', 'readList'))->toBe('readList');
        });

        test('rejects invalid non-null keys', function () {
            $child = new KeyOfBoundChild();

            expect(fn () => $child->getConfiguration('menu', 'nonExistent'))
                ->toThrow(TypeError::class, 'Argument $key')
            ;
        });
    });

    describe('3. Offset access return type (@return T[K])', function () {
        test('validates return value against the specific field type T[K]', function () {
            $child = new KeyOfBoundMixedShapeChild();

            expect($child->getFieldValue('id', 42))->toBe(42);
            expect($child->getFieldValue('name', 'Alice'))->toBe('Alice');
            expect($child->getFieldValue('enabled', true))->toBeTrue();
        });

        test('rejects return value that mismatches T[K] for the passed literal key', function () {
            $child = new KeyOfBoundMixedShapeChild();

            expect(fn () => $child->getFieldValue('id', 'not-an-int'))
                ->toThrow(TypeError::class, 'Return value')
            ;

            expect(fn () => $child->getFieldValue('name', 123))
                ->toThrow(TypeError::class, 'Return value')
            ;
        });
    });

    describe('4. Integer-keyed shapes, generic array maps, and standalone functions', function () {
        test('handles integer literal keys in array shapes', function () {
            $child = new KeyOfBoundIntShapeChild();

            expect($child->getByAnyKey(10))->toBe(10);
            expect($child->getByAnyKey(20))->toBe(20);

            expect(fn () => $child->getByAnyKey(30))
                ->toThrow(TypeError::class, 'Argument $key')
            ;
        });

        test('handles key-of<array<string, mixed>>', function () {
            $child = new KeyOfBoundStringMapChild();

            expect($child->get('anyStringKey'))->toBe('anyStringKey');
        });

        test('handles key-of<array<int, string>>', function () {
            $child = new KeyOfBoundIntMapChild();

            expect($child->getByAnyKey(0))->toBe(0);
            expect($child->getByAnyKey(99))->toBe(99);

            expect(fn () => $child->getByAnyKey('wrong'))
                ->toThrow(TypeError::class, 'Argument $key')
            ;
        });

        test('handles standalone function with @template T and @template K of key-of<T>', function () {
            expect(keyOfBoundPick(['a' => 1, 'b' => 2], 'a'))->toBe(1);
        });
    });

    describe('5. value-of<T> and BackedEnum key-of<E> / value-of<E> bounds', function () {
        test('handles @template V of value-of<T> on literal-valued array shape', function () {
            $child = new KeyOfBoundLiteralValuesChild();

            expect($child->acceptValue('red'))->toBe('red');
            expect($child->acceptValue('blue'))->toBe('blue');

            expect(fn () => $child->acceptValue('green'))
                ->toThrow(TypeError::class, 'Argument $value')
            ;
        });

        test('handles @template K of key-of<E> and @template V of value-of<E> on BackedEnum', function () {
            $repo = new KeyOfBoundStatusRepo();

            expect($repo->byCaseName('Active'))->toBe('Active');
            expect($repo->byCaseName('Archived'))->toBe('Archived');
            expect(fn () => $repo->byCaseName('Deleted'))
                ->toThrow(TypeError::class, 'Argument $caseName')
            ;

            expect($repo->byCaseValue('active'))->toBe('active');
            expect($repo->byCaseValue('archived'))->toBe('archived');
            expect(fn () => $repo->byCaseValue('deleted'))
                ->toThrow(TypeError::class, 'Argument $caseValue')
            ;
        });
    });
});
