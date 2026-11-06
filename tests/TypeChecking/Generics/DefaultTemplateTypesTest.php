<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\TypePHP;

/**
 * 1. Default template without explicit bound (@template T = string)
 *
 * @template T = string
 *
 * @param mixed $value
 *
 * @return T
 */
function testDefaultTemplateString(mixed $value): mixed
{
    return $value;
}

/**
 * 2. Default template with explicit bound (@template T of object = stdClass)
 *
 * @template T of object = stdClass
 *
 * @param mixed $value
 *
 * @return T
 */
function testDefaultTemplateObject(mixed $value): mixed
{
    return $value;
}

/**
 * 3. Class with default template type (@template T = int) and no constructor
 *
 * @template T = int
 */
class DefaultIntBox
{
    /**
     * @var list<T>
     */
    public array $items = [];

    /**
     * @param T $item
     */
    public function add(mixed $item): void
    {
        $this->items[] = $item;
    }
}

/**
 * 4. Class with default template type (@template T = int) and optional constructor parameter
 *
 * @template T = int
 */
class DefaultBoxWithCtor
{
    /**
     * @var T|null
     */
    public mixed $value = null;

    /**
     * @param T|null $initial
     */
    public function __construct(mixed $initial = null)
    {
        $this->value = $initial;
    }

    /**
     * @param T $val
     */
    public function set(mixed $val): void
    {
        $this->value = $val;
    }
}

/**
 * 5. Multi-template class with partial default (@template K of array-key = string, @template V)
 *
 * @template K of array-key = string
 * @template V
 */
class PartialDefaultMap
{
    /**
     * @var array<K, V>
     */
    public array $entries = [];

    /**
     * @param K $key
     * @param V $val
     */
    public function put(mixed $key, mixed $val): void
    {
        $this->entries[$key] = $val;
    }
}

describe('Default Template Annotations (@template T = DefaultType)', function () {
    describe('1. Standalone Function Default Templates', function () {
        test('uses default template type string when template T is unbound', function () {
            expect(testDefaultTemplateString('hello'))->toBe('hello');

            expect(fn () => testDefaultTemplateString(12345))
                ->toThrow(TypeError::class, 'Return value')
            ;
        });

        test('uses default template type stdClass when template has both bound and default', function () {
            $std = new stdClass();
            expect(testDefaultTemplateObject($std))->toBe($std);

            expect(fn () => testDefaultTemplateObject(new Dog()))
                ->toThrow(TypeError::class, 'Return value')
            ;
        });
    });

    describe('2. Unannotated Class Instantiation with Default Template (@template T = int)', function () {
        test('records default template type on unannotated instance in getGenericTypes and getGenericType', function () {
            $box = new DefaultIntBox();

            expect(TypePHP::getGenericTypes($box))->toBe(['T' => 'int'])
                ->and(TypePHP::getGenericType($box))->toBe('int')
            ;
        });

        test('enforces default template type on method calls and property mutations for unannotated instance', function () {
            $freshBox = new DefaultIntBox();

            expect(fn () => $freshBox->add('not_an_int'))
                ->toThrow(TypeError::class, 'must be of type int')
            ;

            $box = new DefaultIntBox();
            $box->add(42);
            expect($box->items)->toBe([42]);

            expect(fn () => $box->add('not_an_int'))
                ->toThrow(TypeError::class, 'must be of type int')
            ;

            expect(function () use ($box) {
                $box->items[] = 'not_an_int';
            })->toThrow(TypeError::class, 'must be of type int');
        });

        test('allows @var annotation to override the default template type', function () {
            /** @var DefaultIntBox<string> $stringBox */
            $stringBox = new DefaultIntBox();

            expect(TypePHP::getGenericTypes($stringBox))->toBe(['T' => 'string'])
                ->and(TypePHP::getGenericType($stringBox))->toBe('string')
            ;

            $stringBox->add('hello');
            expect($stringBox->items)->toBe(['hello']);

            expect(fn () => $stringBox->add(42))
                ->toThrow(TypeError::class, 'must be of type string')
            ;
        });
    });

    describe('3. Constructor Inference vs Default Template Fallback', function () {
        test('falls back to default T = int when constructor is called with no arguments', function () {
            $box = new DefaultBoxWithCtor();

            expect(TypePHP::getGenericTypes($box))->toBe(['T' => 'int']);

            $box->set(100);
            expect($box->value)->toBe(100);

            expect(fn () => $box->set('wrong'))
                ->toThrow(TypeError::class, 'must be of type int')
            ;
        });

        test('overrides default T = int when constructor argument infers T = string', function () {
            $box = new DefaultBoxWithCtor('initial_string');

            expect(TypePHP::getGenericTypes($box))->toBe(['T' => 'string']);

            $box->set('updated_string');
            expect($box->value)->toBe('updated_string');

            expect(fn () => $box->set(100))
                ->toThrow(TypeError::class, 'must be of type string')
            ;
        });
    });

    describe('4. Partial Defaults on Multi-Template Classes', function () {
        test('records K = string default on instantiation and infers V on first method call', function () {
            $map = new PartialDefaultMap();

            expect(TypePHP::getGenericTypes($map))->toBe(['K' => 'string']);

            expect(fn () => $map->put(123, 'value'))
                ->toThrow(TypeError::class, 'must be of type string')
            ;

            $map->put('first_key', 42);

            expect(TypePHP::getGenericTypes($map))->toBe([
                'K' => 'string',
                'V' => 'int',
            ]);

            expect(fn () => $map->put('second_key', 'not_an_int'))
                ->toThrow(TypeError::class, 'must be of type int');
        });
    });
});
