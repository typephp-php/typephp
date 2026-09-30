<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;
use TypePHP\TypePHP;

/**
 * Single-template wrapper
 *
 * @template T
 */
class NestedGenericWrapper
{
    /**
     * @param T $value
     */
    public function __construct(public mixed $value)
    {
    }
}

/**
 * Two-template pair
 *
 * @template TLeft
 * @template TRight
 */
class NestedGenericPair
{
    /**
     * @param TLeft $left
     * @param TRight $right
     */
    public function __construct(public mixed $left, public mixed $right)
    {
    }
}

/**
 * Heterogeneous container
 *
 * @template T
 */
class NestedGenericContainer
{
    /**
     * @param T $item
     */
    public function __construct(public mixed $item)
    {
    }
}

/**
 * Heterogeneous box
 *
 * @template T
 */
class NestedGenericBox
{
    /**
     * @param T $content
     */
    public function __construct(public mixed $content)
    {
    }
}

/**
 * Constructor with optional default parameters alongside template T
 *
 * @template T
 */
class NestedWithDefaultArg
{
    /**
     * @param T $value
     * @param non-empty-string $tag
     */
    public function __construct(public mixed $value, public string $tag = 'default')
    {
    }
}

describe('Nested Generic Instantiation & Argument Pre-binding Isolation', function () {
    describe('Control Cases: Single Level & Intermediate Variables', function () {
        test('instantiates single-level generic without annotation using dynamic inference', function () {
            $a = new NestedGenericWrapper(42);

            expect($a->value)->toBe(42)
                ->and(TypePHP::getGenericType($a))->toBe('int')
            ;
        });

        test('instantiates single-level generic with @var annotation', function () {
            /** @var NestedGenericWrapper<int> $b */
            $b = new NestedGenericWrapper(42);

            expect($b->value)->toBe(42)
                ->and(TypePHP::getGenericType($b))->toBe('int')
            ;
        });

        test('instantiates nested generic when separated into intermediate variables', function () {
            $inner = new NestedGenericWrapper(42);

            /** @var NestedGenericWrapper<NestedGenericWrapper<int>> $c */
            $c = new NestedGenericWrapper($inner);

            expect($c->value)->toBe($inner)
                ->and($c->value->value)->toBe(42)
            ;
        });

        test('instantiates direct nested generic without any @var annotation', function () {
            $d = new NestedGenericWrapper(new NestedGenericWrapper(42));

            expect($d->value)->toBeInstanceOf(NestedGenericWrapper::class)
                ->and($d->value->value)->toBe(42)
            ;
        });
    });

    describe('Direct Nested new Expressions with @var Annotation (The Bug & Multi-Level Fix)', function () {
        test('instantiates 2-level direct nested new with @var Wrapper<Wrapper<int>>', function () {
            /** @var NestedGenericWrapper<NestedGenericWrapper<int>> $e */
            $e = new NestedGenericWrapper(new NestedGenericWrapper(42));

            expect($e->value)->toBeInstanceOf(NestedGenericWrapper::class)
                ->and($e->value->value)->toBe(42)
            ;
        });

        test('instantiates 3-level direct nested new with @var Wrapper<Wrapper<Wrapper<int>>>', function () {
            /** @var NestedGenericWrapper<NestedGenericWrapper<NestedGenericWrapper<int>>> $f */
            $f = new NestedGenericWrapper(new NestedGenericWrapper(new NestedGenericWrapper(42)));

            expect($f->value->value->value)->toBe(42);
        });

        test('instantiates 4-level direct nested new without hijacking inner templates', function () {
            /** @var NestedGenericWrapper<NestedGenericWrapper<NestedGenericWrapper<NestedGenericWrapper<string>>>> $deep */
            $deep = new NestedGenericWrapper(new NestedGenericWrapper(new NestedGenericWrapper(new NestedGenericWrapper('deep_val'))));

            expect($deep->value->value->value->value)->toBe('deep_val');
        });
    });

    describe('Strict Type Violation Detection at Deep Levels', function () {
        test('throws TypeError on 2-level nesting when innermost constructor receives invalid argument', function () {
            expect(function () {
                /** @var NestedGenericWrapper<NestedGenericWrapper<int>> $e */
                $e = new NestedGenericWrapper(new NestedGenericWrapper('not an int'));
            })->toThrow(TypeError::class, 'expects');
        });

        test('throws TypeError on 3-level nesting when innermost argument violates type contract', function () {
            expect(function () {
                /** @var NestedGenericWrapper<NestedGenericWrapper<NestedGenericWrapper<int>>> $f */
                $f = new NestedGenericWrapper(new NestedGenericWrapper(new NestedGenericWrapper('string_instead_of_int')));
            })->toThrow(TypeError::class, 'expects');
        });

        test('throws TypeError on 3-level nesting when middle level is not a Wrapper', function () {
            expect(function () {
                /** @var NestedGenericWrapper<NestedGenericWrapper<NestedGenericWrapper<int>>> $f */
                $f = new NestedGenericWrapper(new NestedGenericWrapper(42));
            })->toThrow(TypeError::class, 'expects');
        });
    });

    describe('Multiple Generic Arguments with Nested new (Pair<Left, Right>)', function () {
        test('accepts valid nested new instances across multiple template parameters', function () {
            /** @var NestedGenericPair<NestedGenericWrapper<int>, NestedGenericWrapper<string>> $pair */
            $pair = new NestedGenericPair(new NestedGenericWrapper(10), new NestedGenericWrapper('hello'));

            expect($pair->left->value)->toBe(10)
                ->and($pair->right->value)->toBe('hello')
            ;
        });

        test('throws TypeError when left branch of pair receives invalid nested type', function () {
            expect(function () {
                /** @var NestedGenericPair<NestedGenericWrapper<int>, NestedGenericWrapper<string>> $pair */
                $pair = new NestedGenericPair(new NestedGenericWrapper('not_an_int'), new NestedGenericWrapper('hello'));
            })->toThrow(TypeError::class, 'expects');
        });

        test('throws TypeError when right branch of pair receives invalid nested type', function () {
            expect(function () {
                /** @var NestedGenericPair<NestedGenericWrapper<int>, NestedGenericWrapper<string>> $pair */
                $pair = new NestedGenericPair(new NestedGenericWrapper(10), new NestedGenericWrapper(12345));
            })->toThrow(TypeError::class, 'expects');
        });
    });

    describe('Heterogeneous Nested Classes (Container<Box<int>>)', function () {
        test('accepts valid nested instantiation across different class names', function () {
            /** @var NestedGenericContainer<NestedGenericBox<int>> $c */
            $c = new NestedGenericContainer(new NestedGenericBox(100));

            expect($c->item)->toBeInstanceOf(NestedGenericBox::class)
                ->and($c->item->content)->toBe(100)
            ;
        });

        test('throws TypeError when inner box contains invalid type', function () {
            expect(function () {
                /** @var NestedGenericContainer<NestedGenericBox<int>> $c */
                $c = new NestedGenericContainer(new NestedGenericBox('not_an_int'));
            })->toThrow(TypeError::class, 'expects');
        });

        test('throws TypeError when inner container is wrong class', function () {
            expect(function () {
                /** @var NestedGenericContainer<NestedGenericBox<int>> $c */
                $c = new NestedGenericContainer(new NestedGenericWrapper(100));
            })->toThrow(TypeError::class, 'must be an instance of');
        });
    });

    describe('PHP 8.0+ Named Arguments in Nested Instantiations', function () {
        test('accepts nested new passed via named arguments', function () {
            /** @var NestedGenericWrapper<NestedGenericWrapper<int>> $w */
            $w = new NestedGenericWrapper(value: new NestedGenericWrapper(value: 42));

            expect($w->value->value)->toBe(42);
        });

        test('accepts swapped named arguments on multi-template pair with nested new', function () {
            /** @var NestedGenericPair<NestedGenericWrapper<int>, NestedGenericWrapper<string>> $pair */
            $pair = new NestedGenericPair(
                right: new NestedGenericWrapper(value: 'right_val'),
                left: new NestedGenericWrapper(value: 100)
            );

            expect($pair->left->value)->toBe(100)
                ->and($pair->right->value)->toBe('right_val')
            ;
        });
    });

    describe('Constructor Default Arguments alongside Nested Generics', function () {
        test('accepts nested generic instantiation when constructors declare default arguments', function () {
            /** @var NestedWithDefaultArg<NestedWithDefaultArg<int>> $w */
            $w = new NestedWithDefaultArg(new NestedWithDefaultArg(42));

            expect($w->value->value)->toBe(42)
                ->and($w->tag)->toBe('default')
                ->and($w->value->tag)->toBe('default')
            ;
        });

        test('allows custom non-default argument on inner and outer constructors', function () {
            /** @var NestedWithDefaultArg<NestedWithDefaultArg<int>> $w */
            $w = new NestedWithDefaultArg(new NestedWithDefaultArg(42, 'inner_tag'), 'outer_tag');

            expect($w->tag)->toBe('outer_tag')
                ->and($w->value->tag)->toBe('inner_tag')
            ;
        });

        test('throws TypeError when inner default argument violates string constraint', function () {
            expect(function () {
                /** @var NestedWithDefaultArg<NestedWithDefaultArg<int>> $w */
                $w = new NestedWithDefaultArg(new NestedWithDefaultArg(42, ''));
            })->toThrow(TypeError::class, 'non-empty-string');
        });
    });

    describe('Reified Generic Type Inspection (TypePHP::getGenericType)', function () {
        test('reifies correct generic template types at every level in WeakMap', function () {
            /** @var NestedGenericWrapper<NestedGenericWrapper<int>> $e */
            $e = new NestedGenericWrapper(new NestedGenericWrapper(42));

            expect(TypePHP::getGenericType($e))->toContain(NestedGenericWrapper::class)
                ->and(TypePHP::getGenericType($e->value))->toBe('int')
            ;
        });

        test('reifies both branches of nested pair independently', function () {
            /** @var NestedGenericPair<NestedGenericWrapper<int>, NestedGenericWrapper<string>> $pair */
            $pair = new NestedGenericPair(new NestedGenericWrapper(10), new NestedGenericWrapper('hello'));

            expect(TypePHP::getGenericType($pair->left))->toBe('int')
                ->and(TypePHP::getGenericType($pair->right))->toBe('string')
            ;
        });
    });

    describe('Nested Instantiation Combined with Collections (Wrapper<list<Wrapper<int>>>)', function () {
        test('accepts list containing nested generic instances', function () {
            /** @var NestedGenericWrapper<list<NestedGenericWrapper<int>>> $w */
            $w = new NestedGenericWrapper([
                new NestedGenericWrapper(10),
                new NestedGenericWrapper(20),
            ]);

            expect($w->value[0]->value)->toBe(10)
                ->and($w->value[1]->value)->toBe(20)
            ;
        });

        test('throws TypeError when an item inside the list violates generic type', function () {
            expect(function () {
                /** @var NestedGenericWrapper<list<NestedGenericWrapper<int>>> $w */
                $w = new NestedGenericWrapper([
                    new NestedGenericWrapper(10),
                    new NestedGenericWrapper('invalid_item'),
                ]);
            })->toThrow(TypeError::class, 'expects');
        });
    });
});
