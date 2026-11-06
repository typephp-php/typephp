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
 * 3. Standalone function with default template in conditional return
 *
 * @template T = int
 *
 * @param T|null $sample
 * @param mixed $returnValue
 *
 * @return (T is int ? positive-int : non-empty-string)
 */
function testDefaultTemplateConditionalReturn(mixed $sample = null, mixed $returnValue = null): mixed
{
    return $returnValue;
}

/**
 * 4. Standalone function with default template in conditional parameter
 *
 * @template T = int
 *
 * @param (T is int ? positive-int : non-empty-string) $payload
 * @param T|null $sample
 */
function testDefaultTemplateConditionalParam(mixed $payload, mixed $sample = null): mixed
{
    return $payload;
}

/**
 * 5. Standalone function with default template in conditional @param-out
 *
 * @template T = int
 *
 * @param mixed &$out
 * @param mixed $newValue
 * @param T|null $sample
 *
 * @param-out (T is int ? positive-int : non-empty-string) $out
 */
function testDefaultTemplateConditionalParamOut(mixed &$out, mixed $newValue, mixed $sample = null): void
{
    $out = $newValue;
}

/**
 * 6. Class with default template type (@template T = int) and no constructor
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

    /**
     * Zero-parameter method with conditional return based on class template T
     *
     * @return (T is int ? positive-int : non-empty-string)
     */
    public function getDefaultConditionalValue(mixed $val): mixed
    {
        return $this->computeConditional($val);
    }

    /**
     * @return (T is int ? positive-int : non-empty-string)
     */
    public function computeOne(): mixed
    {
        return 42;
    }

    /**
     * @return (T is int ? positive-int : non-empty-string)
     */
    public function computeInvalidNegative(): mixed
    {
        return -10;
    }

    /**
     * @param mixed &$out
     *
     * @param-out T $out
     */
    public function exportTo(mixed &$out, mixed $val): void
    {
        $out = $val;
    }

    /**
     * @param mixed &$out
     *
     * @param-out (T is int ? positive-int : non-empty-string) $out
     */
    public function exportConditionalTo(mixed &$out, mixed $val): void
    {
        $out = $val;
    }

    /**
     * @return (T is int ? positive-int : non-empty-string)
     */
    private function computeConditional(mixed $val): mixed
    {
        return $val;
    }
}

/**
 * 7. Class with default template type (@template T = int) and optional constructor parameter
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
 * 8. Multi-template class with partial default (@template K of array-key = string, @template V)
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

            // Dog is an object (satisfies bound 'object'), but NOT stdClass (violates default 'stdClass')
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

            // Must reject string even on the very first method call before any valid int is added!
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

            // Key must already be enforced as string even on the first call!
            expect(fn () => $map->put(123, 'value'))
                ->toThrow(TypeError::class, 'must be of type string')
            ;

            $map->put('first_key', 42);

            expect(TypePHP::getGenericTypes($map))->toBe([
                'K' => 'string',
                'V' => 'int',
            ]);

            expect(fn () => $map->put('second_key', 'not_an_int'))
                ->toThrow(TypeError::class, 'must be of type int')
            ;
        });
    });

    describe('5. Conditional Types with Default Templates (T is Target ? A : B)', function () {
        test('evaluates true branch (positive-int) on standalone function when T falls back to default int', function () {
            expect(testDefaultTemplateConditionalReturn(null, 42))->toBe(42);

            expect(fn () => testDefaultTemplateConditionalReturn(null, -10))
                ->toThrow(TypeError::class, 'positive-int')
            ;

            expect(fn () => testDefaultTemplateConditionalReturn(null, 'string_val'))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        });

        test('evaluates else branch (non-empty-string) on standalone function when T is overridden to string', function () {
            expect(testDefaultTemplateConditionalReturn('override', 'valid_string'))->toBe('valid_string');

            expect(fn () => testDefaultTemplateConditionalReturn('override', ''))
                ->toThrow(TypeError::class, 'non-empty-string')
            ;
        });

        test('evaluates conditional parameter using default T = int when sample is omitted', function () {
            expect(testDefaultTemplateConditionalParam(42))->toBe(42);

            expect(fn () => testDefaultTemplateConditionalParam(-5))
                ->toThrow(TypeError::class, 'positive-int')
            ;

            expect(fn () => testDefaultTemplateConditionalParam('not_int'))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        });

        test('evaluates conditional return on zero-parameter class methods using class default T = int', function () {
            $box = new DefaultIntBox();

            expect($box->computeOne())->toBe(42);

            expect(fn () => $box->computeInvalidNegative())
                ->toThrow(TypeError::class, 'positive-int')
            ;

            expect($box->getDefaultConditionalValue(100))->toBe(100);

            expect(fn () => $box->getDefaultConditionalValue(-50))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        });

        test('evaluates else branch on class conditional return when default is overridden via @var DefaultIntBox<string>', function () {
            /** @var DefaultIntBox<string> $stringBox */
            $stringBox = new DefaultIntBox();

            expect($stringBox->getDefaultConditionalValue('hello'))->toBe('hello');

            expect(fn () => $stringBox->getDefaultConditionalValue(''))
                ->toThrow(TypeError::class, 'non-empty-string')
            ;
        });
    });

    describe('6. @param-out with Default Templates & Conditional @param-out', function () {
        test('enforces default T = int on @param-out T $out for unannotated class instance', function () {
            $box = new DefaultIntBox();
            $out = null;

            $box->exportTo($out, 99);
            expect($out)->toBe(99);

            expect(fn () => $box->exportTo($out, 'not_an_int'))
                ->toThrow(TypeError::class, 'Argument &$out (param-out) must be of type int')
            ;
        });

        test('enforces overridden T = string on @param-out T $out when pre-bound via @var', function () {
            /** @var DefaultIntBox<string> $stringBox */
            $stringBox = new DefaultIntBox();
            $out = null;

            $stringBox->exportTo($out, 'valid_str');
            expect($out)->toBe('valid_str');

            expect(fn () => $stringBox->exportTo($out, 12345))
                ->toThrow(TypeError::class, 'Argument &$out (param-out) must be of type string')
            ;
        });

        test('enforces conditional @param-out (T is int ? positive-int : non-empty-string) on class method', function () {
            $box = new DefaultIntBox();
            $out = null;

            $box->exportConditionalTo($out, 50);
            expect($out)->toBe(50);

            expect(fn () => $box->exportConditionalTo($out, -20))
                ->toThrow(TypeError::class, 'Argument &$out (param-out) must be of type positive-int')
            ;

            /** @var DefaultIntBox<string> $stringBox */
            $stringBox = new DefaultIntBox();
            $stringBox->exportConditionalTo($out, 'active');
            expect($out)->toBe('active');

            expect(fn () => $stringBox->exportConditionalTo($out, ''))
                ->toThrow(TypeError::class, 'Argument &$out (param-out) must be of type non-empty-string')
            ;
        });

        test('enforces conditional @param-out on standalone function with default T = int', function () {
            $out = null;

            testDefaultTemplateConditionalParamOut($out, 100);
            expect($out)->toBe(100);

            expect(fn () => testDefaultTemplateConditionalParamOut($out, -5))
                ->toThrow(TypeError::class, 'Argument &$out (param-out) must be of type positive-int')
            ;

            // Overriding T = string by passing 'sample_str'
            testDefaultTemplateConditionalParamOut($out, 'hello', 'sample_str');
            expect($out)->toBe('hello');

            expect(fn () => testDefaultTemplateConditionalParamOut($out, '', 'sample_str'))
                ->toThrow(TypeError::class, 'Argument &$out (param-out) must be of type non-empty-string')
            ;
        });
    });
});
