<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\GenericBox;
use TypePHP\Tests\Fixtures\Properties\PropertyRefTargetFixture;

/**
 * Declared in the SAME file to prove AST-Level local property fallback
 * when Composer cannot autoload the class during compile-time.
 */
class SameFilePropertyRefFixture
{
    /**
     * @var positive-int
     */
    public int $localScore = 10;

    /**
     * @var list<int>
     */
    public array $localNumbers = [1, 2, 3];

    /**
     * @var array{id: positive-int, name: non-empty-string}
     */
    public array $localUser = [
        'id' => 1,
        'name' => 'Alice',
    ];

    public function mutateInternalScoreByRef(mixed $val): void
    {
        $ref = &$this->localScore;
        $ref = $val;
    }
}

class GenericRefUser
{
    public function __construct(public string $name = 'Alice')
    {
    }
}

class GenericRefProduct
{
    public function __construct(public string $sku = 'PROD-1')
    {
    }
}

/**
 * @template T
 */
class SameFileGenericBox
{
    /**
     * @param T $value
     */
    public function __construct(
        public mixed $value
    ) {
    }

    public function mutateInternalValueByRef(mixed $val): void
    {
        $ref = &$this->value;
        $ref = $val;
    }
}

/**
 * @template K of array-key
 * @template V
 */
class SameFileGenericMap
{
    /**
     * @param K $key
     * @param V $val
     */
    public function __construct(
        public mixed $key,
        public mixed $val
    ) {
    }
}

/**
 * Generic fixture with list<T> property
 *
 * @template T
 */
class GenericListBoxFixture
{
    /**
     * @var list<T>
     */
    public array $items = [];

    /**
     * @param list<T> $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public function mutateItemByRef(int $index, mixed $val): void
    {
        $ref = &$this->items[$index];
        $ref = $val;
    }

    public function appendItemViaRef(mixed $val): void
    {
        $ref = &$this->items;
        $ref[] = $val;
    }
}

/**
 * Generic fixture with typed array T[] property
 *
 * @template T
 */
class GenericTypedArrayBoxFixture
{
    /**
     * @var T[]
     */
    public array $items = [];

    /**
     * @param T[] $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }
}

/**
 * Generic fixture with array<K, V> map property
 *
 * @template K of array-key
 * @template V
 */
class GenericMapBoxFixture
{
    /**
     * @var array<K, V>
     */
    public array $items = [];

    /**
     * @param array<K, V> $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }
}

describe('By-Reference Property Aliasing ($ref = &$obj->prop)', function () {
    beforeEach(function () {
        PropertyRefTargetFixture::reset();
    });

    describe('1. External Instance Property Reference Aliasing ($ref = &$box->prop)', function () {
        test('throws TypeError when assigning invalid value to reference aliasing a scalar property', function () {
            $box = new PropertyRefTargetFixture();

            $ref = &$box->score;

            expect(function () use (&$ref) {
                $ref = -50;
            })->toThrow(TypeError::class, 'positive-int');
        });

        test('allows valid assignment to reference aliasing a scalar property and mutates property in place', function () {
            $box = new PropertyRefTargetFixture();

            $ref = &$box->score;
            $ref = 42;

            expect($box->score)->toBe(42)
                ->and($ref)->toBe(42)
            ;
        });
    });

    describe('2. External Property Array Dimension Reference ($ref = &$box->numbers[0])', function () {
        test('throws TypeError when assigning invalid value to reference aliasing an array property element', function () {
            $box = new PropertyRefTargetFixture();

            $ref = &$box->numbers[0];

            expect(function () use (&$ref) {
                $ref = 'not_an_int';
            })->toThrow(TypeError::class, 'must be of type int');
        });

        test('allows valid assignment to reference aliasing an array property element and mutates array in place', function () {
            $box = new PropertyRefTargetFixture();

            $ref = &$box->numbers[0];
            $ref = 999;

            expect($box->numbers[0])->toBe(999)
                ->and($ref)->toBe(999)
            ;
        });
    });

    describe('3. External Property Array Shape Key Reference ($ref = &$box->user[\'name\'])', function () {
        test('throws TypeError when assigning invalid value to reference aliasing a shape property key', function () {
            $box = new PropertyRefTargetFixture();

            $ref = &$box->user['name'];

            expect(function () use (&$ref) {
                $ref = ''; // Violates non-empty-string
            })->toThrow(TypeError::class, 'non-empty-string');
        });

        test('throws TypeError when assigning invalid value to reference aliasing a positive-int shape key', function () {
            $box = new PropertyRefTargetFixture();

            $ref = &$box->user['id'];

            expect(function () use (&$ref) {
                $ref = -1; // Violates positive-int
            })->toThrow(TypeError::class, 'positive-int');
        });
    });

    describe('4. Internal $this Property Reference Aliasing ($ref = &$this->prop)', function () {
        test('throws TypeError when internal method mutates $this scalar property via reference alias', function () {
            $box = new PropertyRefTargetFixture();

            expect(function () use ($box) {
                $box->mutateInternalScoreByRef(-99);
            })->toThrow(TypeError::class, 'positive-int');

            expect($box->score)->toBe(10);
        });

        test('throws TypeError when internal method mutates $this array element via reference alias', function () {
            $box = new PropertyRefTargetFixture();

            expect(function () use ($box) {
                $box->mutateInternalNumberByRef('invalid_element');
            })->toThrow(TypeError::class, 'must be of type int');

            expect($box->numbers[0])->toBe(1);
        });

        test('throws TypeError when internal method mutates $this shape key via reference alias', function () {
            $box = new PropertyRefTargetFixture();

            expect(function () use ($box) {
                $box->mutateInternalUserByRef('');
            })->toThrow(TypeError::class, 'non-empty-string');

            expect($box->user['name'])->toBe('Alice');
        });
    });

    describe('5. Static Property Reference Aliasing ($ref = &Class::$prop)', function () {
        test('throws TypeError when assigning invalid value to reference aliasing a static property', function () {
            $ref = &PropertyRefTargetFixture::$staticScore;

            expect(function () use (&$ref) {
                $ref = -100;
            })->toThrow(TypeError::class, 'positive-int');

            expect(PropertyRefTargetFixture::$staticScore)->toBe(100);
        });
    });

    describe('6. Same-File Declared Class Property Reference Aliasing (AST-Level Fallback)', function () {
        test('throws TypeError on scalar reference mutation for class declared in the same file', function () {
            $local = new SameFilePropertyRefFixture();

            $ref = &$local->localScore;

            expect(function () use (&$ref) {
                $ref = -25;
            })->toThrow(TypeError::class, 'positive-int');

            expect($local->localScore)->toBe(10);
        });

        test('throws TypeError on array dimension reference mutation for class declared in the same file', function () {
            $local = new SameFilePropertyRefFixture();

            $ref = &$local->localNumbers[0];

            expect(function () use (&$ref) {
                $ref = 'wrong_type';
            })->toThrow(TypeError::class, 'must be of type int');

            expect($local->localNumbers[0])->toBe(1);
        });

        test('throws TypeError on shape key reference mutation for class declared in the same file', function () {
            $local = new SameFilePropertyRefFixture();

            $ref = &$local->localUser['name'];

            expect(function () use (&$ref) {
                $ref = '';
            })->toThrow(TypeError::class, 'non-empty-string');

            expect($local->localUser['name'])->toBe('Alice');
        });

        test('throws TypeError on $this reference mutation for class declared in the same file', function () {
            $local = new SameFilePropertyRefFixture();

            expect(function () use ($local) {
                $local->mutateInternalScoreByRef(-99);
            })->toThrow(TypeError::class, 'positive-int');

            expect($local->localScore)->toBe(10);
        });
    });

    describe('7. Generic Property Reference Aliasing ($ref = &$genericBox->item)', function () {
        test('throws TypeError when assigning invalid value to reference aliasing a generic property (GenericBox<Dog>)', function () {
            /** @var GenericBox<Dog> $dogBox */
            $dogBox = new GenericBox();
            $dogBox->item = new Dog();

            $ref = &$dogBox->item;

            expect(function () use (&$ref) {
                $ref = new Car();
            })->toThrow(TypeError::class, Dog::class);
        });

        test('allows valid assignment to reference aliasing a generic property and mutates property in place', function () {
            /** @var GenericBox<Dog> $dogBox */
            $dogBox = new GenericBox();
            $dogBox->item = new Dog();

            $ref = &$dogBox->item;
            $newDog = new Dog();
            $ref = $newDog;

            expect($dogBox->item)->toBe($newDog)
                ->and($ref)->toBe($newDog)
            ;
        });

        test('throws TypeError when assigning invalid value to reference aliasing a promoted generic property (User Bug Reproduction)', function () {
            /** @var SameFileGenericBox<GenericRefUser> $userBox */
            $userBox = new SameFileGenericBox(new GenericRefUser());

            $reference = &$userBox->value;

            expect(function () use (&$reference) {
                $reference = new GenericRefProduct();
            })->toThrow(TypeError::class, GenericRefUser::class);
        });

        test('throws TypeError when assigning invalid value to reference aliasing multi-template generic property', function () {
            /** @var SameFileGenericMap<string, positive-int> $map */
            $map = new SameFileGenericMap('timeout', 10);

            $keyRef = &$map->key;
            $valRef = &$map->val;

            expect(function () use (&$valRef) {
                $valRef = -50;
            })->toThrow(TypeError::class, 'positive-int');

            expect(function () use (&$keyRef) {
                $keyRef = 12345;
            })->toThrow(TypeError::class, 'must be of type string');
        });

        test('throws TypeError when internal method mutates generic $this property via reference alias', function () {
            /** @var SameFileGenericBox<GenericRefUser> $userBox */
            $userBox = new SameFileGenericBox(new GenericRefUser());

            expect(function () use ($userBox) {
                $userBox->mutateInternalValueByRef(new GenericRefProduct());
            })->toThrow(TypeError::class, GenericRefUser::class);

            expect($userBox->value)->toBeInstanceOf(GenericRefUser::class);
        });
    });

    describe('8. Generic Array Properties: By-Reference Aliasing & Element Mutations', function () {
        test('throws TypeError when assigning invalid value to reference aliasing an element of generic list<T>', function () {
            /** @var GenericListBoxFixture<Dog> $box */
            $box = new GenericListBoxFixture([new Dog()]);

            $ref = &$box->items[0];

            expect(function () use (&$ref) {
                $ref = new Car();
            })->toThrow(TypeError::class, Dog::class);

            expect($box->items[0])->toBeInstanceOf(Dog::class);
        });

        test('throws TypeError when assigning invalid value to reference aliasing an element of generic T[]', function () {
            /** @var GenericTypedArrayBoxFixture<Dog> $box */
            $box = new GenericTypedArrayBoxFixture([new Dog()]);

            $ref = &$box->items[0];

            expect(function () use (&$ref) {
                $ref = new Car();
            })->toThrow(TypeError::class, Dog::class);

            expect($box->items[0])->toBeInstanceOf(Dog::class);
        });

        test('throws TypeError when assigning invalid value to reference aliasing an element of generic array<K, V>', function () {
            /** @var GenericMapBoxFixture<string, Dog> $box */
            $box = new GenericMapBoxFixture(['pet' => new Dog()]);

            $ref = &$box->items['pet'];

            expect(function () use (&$ref) {
                $ref = new Car();
            })->toThrow(TypeError::class, Dog::class);

            expect($box->items['pet'])->toBeInstanceOf(Dog::class);
        });

        test('throws TypeError when appending invalid value to reference aliasing the entire generic array property', function () {
            /** @var GenericListBoxFixture<Dog> $box */
            $box = new GenericListBoxFixture([new Dog()]);

            $ref = &$box->items;

            expect(function () use (&$ref) {
                $ref[] = new Car();
            })->toThrow(TypeError::class, Dog::class);

            expect($box->items)->toHaveCount(1);
        });

        test('throws TypeError when assigning by index to reference aliasing the entire generic array property', function () {
            /** @var GenericListBoxFixture<Dog> $box */
            $box = new GenericListBoxFixture([new Dog()]);

            $ref = &$box->items;

            expect(function () use (&$ref) {
                $ref[0] = new Car();
            })->toThrow(TypeError::class, Dog::class);

            expect($box->items[0])->toBeInstanceOf(Dog::class);
        });

        test('throws TypeError on direct array append to generic property ($box->items[] = new Car())', function () {
            /** @var GenericListBoxFixture<Dog> $box */
            $box = new GenericListBoxFixture();

            expect(function () use ($box) {
                $box->items[] = new Car();
            })->toThrow(TypeError::class, Dog::class);

            expect($box->items)->toBeEmpty();
        });

        test('throws TypeError on direct index assignment to generic property ($box->items[0] = new Car())', function () {
            /** @var GenericListBoxFixture<Dog> $box */
            $box = new GenericListBoxFixture([new Dog()]);

            expect(function () use ($box) {
                $box->items[0] = new Car();
            })->toThrow(TypeError::class, Dog::class);

            expect($box->items[0])->toBeInstanceOf(Dog::class);
        });

        test('throws TypeError when internal method mutates generic $this array element via reference alias', function () {
            /** @var GenericListBoxFixture<Dog> $box */
            $box = new GenericListBoxFixture([new Dog()]);

            expect(function () use ($box) {
                $box->mutateItemByRef(0, new Car());
            })->toThrow(TypeError::class, Dog::class);

            expect($box->items[0])->toBeInstanceOf(Dog::class);
        });

        test('throws TypeError when internal method appends to generic $this array via reference alias', function () {
            /** @var GenericListBoxFixture<Dog> $box */
            $box = new GenericListBoxFixture([new Dog()]);

            expect(function () use ($box) {
                $box->appendItemViaRef(new Car());
            })->toThrow(TypeError::class, Dog::class);

            expect($box->items)->toHaveCount(1);
        });

        test('allows valid assignment to generic array element reference and mutates in place', function () {
            /** @var GenericListBoxFixture<Dog> $box */
            $box = new GenericListBoxFixture([new Dog()]);

            $ref = &$box->items[0];
            $newDog = new Dog();
            $ref = $newDog;

            expect($box->items[0])->toBe($newDog)
                ->and($ref)->toBe($newDog)
            ;

            $arrayRef = &$box->items;
            $buddy = new Dog();
            $arrayRef[] = $buddy;

            expect($box->items)->toHaveCount(2)
                ->and($box->items[1])->toBe($buddy)
            ;
        });
    });
});
