<?php

declare(strict_types=1);

use TypePHP\Internal\Util\Config;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\Producer;
use TypePHP\Tests\Fixtures\Types\ArrayAccessOnly;
use TypePHP\Tests\Fixtures\Types\CountableArrayAccess;
use TypePHP\Tests\Fixtures\Types\CountableOnly;

/**
 * @return array{0: int, 1: string}
 */
function fetchBroadTuple(int $id, string $name): array
{
    return [$id, $name];
}

beforeEach(function () {
    Config::reset();

    Config::set([
        'inline_vars' => [
            'generics' => true,
            'callables' => true,
            'scalars' => true,
            'arrays' => true,
            'objects' => true,
        ],
    ]);
});

afterEach(function () {
    Config::reset();
});

describe('mixed type validation with @var and param', function () {
    test('enforces stricter inline @var annotation over broader function return contract', function () {
        // Valid call: [10, 'Alice'] satisfies both @return and @var
        /** @var array{0: positive-int, 1: non-empty-string} $userData */
        $userData = fetchBroadTuple(10, 'Alice');
        expect($userData[0])->toBe(10);

        // Invalid call: [-5, 'Alice'] satisfies @return (int), BUT violates @var (positive-int)
        expect(function () {
            /** @var array{0: positive-int, 1: non-empty-string} $userData */
            $userData = fetchBroadTuple(-5, 'Alice');
        })->toThrow(TypeError::class, 'Variable $userData');
    });
});

describe('Inline @var Scalar Validations', function () {
    test('enforces positive-int constraint', function () {
        /** @var positive-int $age */
        $age = 10;
        expect($age)->toBe(10);

        expect(fn () => $age = -5)
            ->toThrow(TypeError::class, 'Variable $age must be of type positive-int')
        ;
    });

    test('ignores scalar validation when disabled in config', function () {
        Config::set(['inline_vars' => ['scalars' => false]]);

        /** @var positive-int $age */
        $age = 10;

        $age = -5;

        expect($age)->toBe(-5);
    });
});

describe('Inline @var Object Validations', function () {
    test('enforces specific class instances', function () {
        /** @var Dog $animal */
        $animal = new Dog();
        expect($animal)->toBeInstanceOf(Dog::class);

        expect(fn () => $animal = new Car())
            ->toThrow(TypeError::class, 'Variable $animal must be of type TypePHP\Tests\Fixtures\Domain\Dog')
        ;
    });

    test('ignores object validation when disabled in config', function () {
        Config::set(['inline_vars' => ['objects' => false]]);

        /** @var Dog $animal */
        $animal = new Dog();
        $animal = new Car();

        expect($animal)->toBeInstanceOf(Car::class);
    });
});

describe('Inline @var Array Shape Validations', function () {
    test('enforces exact array shapes', function () {
        /** @var array{id: int, name: string} $user */
        $user = ['id' => 1, 'name' => 'Alice'];
        expect($user['name'])->toBe('Alice');

        expect(fn () => $user = ['id' => 2])
            ->toThrow(TypeError::class, "Variable \$user is missing required key 'name'")
        ;
    });

    test('ignores shape validation when disabled in config', function () {
        Config::set(['inline_vars' => ['arrays' => false]]);

        /** @var array{id: int, name: string} $user */
        $user = ['id' => 1, 'name' => 'Alice'];

        $user = ['id' => 2];

        expect($user)->toBe(['id' => 2]);
    });
});

describe('Inline @var Nullable and Union Types', function () {
    test('allows null for nullable types', function () {
        /** @var string|null $username */
        $username = 'Alice';
        expect($username)->toBe('Alice');

        $username = null;
        expect($username)->toBeNull();

        expect(fn () => $username = 123)
            ->toThrow(TypeError::class, 'Variable $username must be of type (string | null)')
        ;
    });

    test('allows values matching either side of a union', function () {
        /** @var int|string $identifier */
        $identifier = 42;
        expect($identifier)->toBe(42);

        $identifier = 'user_42';
        expect($identifier)->toBe('user_42');

        expect(fn () => $identifier = false)
            ->toThrow(TypeError::class, 'Variable $identifier must be of type (int | string)')
        ;
    });
});

describe('Inline @var Advanced Unions and Intersections', function () {
    test('enforces inline union of positive-int and non-empty-string', function () {
        /** @var positive-int|non-empty-string $identifier */
        $identifier = 10;
        expect($identifier)->toBe(10);

        $identifier = 'user_10';
        expect($identifier)->toBe('user_10');

        expect(fn () => $identifier = -5)
            ->toThrow(TypeError::class, 'Variable $identifier')
        ;

        expect(fn () => $identifier = '')
            ->toThrow(TypeError::class, 'Variable $identifier')
        ;
    });

    test('enforces inline intersection of Countable and ArrayAccess', function () {
        /** @var Countable&ArrayAccess $collection */
        $collection = new CountableArrayAccess();
        expect($collection)->toBeInstanceOf(CountableArrayAccess::class);

        expect(fn () => $collection = new CountableOnly())
            ->toThrow(TypeError::class, 'Variable $collection')
        ;

        expect(fn () => $collection = new ArrayAccessOnly())
            ->toThrow(TypeError::class, 'Variable $collection')
        ;
    });

    test('enforces inline union of array shapes', function () {
        /** @var array{id: positive-int, status: 'active'} | array{id: positive-int, status: 'error', code: int} $response */
        $response = ['id' => 1, 'status' => 'active'];
        expect($response['status'])->toBe('active');

        $response = ['id' => 2, 'status' => 'error', 'code' => 500];
        expect($response['code'])->toBe(500);

        expect(fn () => $response = ['id' => 3, 'status' => 'pending'])
            ->toThrow(TypeError::class, 'Variable $response')
        ;
    });

    test('enforces inline generics holding unions', function () {
        /** @var Producer<Dog|Cat> $producer */
        $producer = new Producer(new Dog());
        expect($producer->item)->toBeInstanceOf(Dog::class);

        $producer = new Producer(new Cat());
        expect($producer->item)->toBeInstanceOf(Cat::class);

        expect(fn () => $producer = new Producer(new Car()))
            ->toThrow(TypeError::class, 'Variable $producer')
        ;
    });
});

describe('Inline @var Mixed Type', function () {
    test('never validates mixed types (zero cost)', function () {
        /** @var mixed $anything */
        $anything = 10;
        expect($anything)->toBe(10);

        $anything = 'string';
        expect($anything)->toBe('string');

        $anything = new Dog();
        expect($anything)->toBeInstanceOf(Dog::class);
    });
});

describe('Inline @var Initial Assignment vs Reassignment', function () {
    test('validates uninitialized variables upon first assignment', function () {
        /** @var non-empty-string $token */

        expect(fn () => $token = '')
            ->toThrow(TypeError::class, 'Variable $token must be of type non-empty-string')
        ;

        $token = 'valid_token';
        expect($token)->toBe('valid_token');
    });
});

describe('Inline @var Validation for New Features', function () {

    test('enforces array-key on inline local variables', function () {
        /** @var array-key $key */
        $key = 'user_123';
        expect($key)->toBe('user_123');

        $key = 456;
        expect($key)->toBe(456);

        expect(fn () => $key = false)
            ->toThrow(TypeError::class, 'Variable $key must be of type array-key')
        ;
    });

    test('enforces uppercase-string on inline local variables', function () {
        /** @var non-empty-uppercase-string $code */
        $code = 'USD';
        expect($code)->toBe('USD');

        expect(fn () => $code = 'usd')
            ->toThrow(TypeError::class, 'Variable $code must be of type non-empty-uppercase-string')
        ;
    });

    test('enforces key-of on inline local variables', function () {
        /** @var key-of<TypePHP\Tests\Fixtures\Types\DatabaseDriverMap::DRIVER_MAP> $driver */
        $driver = 'pdo_mysql';
        expect($driver)->toBe('pdo_mysql');

        expect(fn () => $driver = 'pdo_invalid')
            ->toThrow(TypeError::class, 'Variable $driver')
        ;
    });

    test('enforces int-mask on inline local variables', function () {
        /** @var int-mask<1, 2, 4> $mask */
        $mask = 3; // 1 | 2
        expect($mask)->toBe(3);

        expect(fn () => $mask = 8)
            ->toThrow(TypeError::class, 'Variable $mask')
        ;
    });

    test('allows initializing empty array for array shape variable before preg_match_all population (Tempest TextBuffer)', function () {
        /** @var array{0: list<string>} $matches */
        $matches = [];
        preg_match_all('/\X/u', '👨‍👩‍👧‍👦ab', $matches);

        expect($matches[0])->toBe(['👨‍👩‍👧‍👦', 'a', 'b']);
    });

    test('still rejects non-empty incomplete array shapes on local variable assignment', function () {
        expect(function () {
            /** @var array{0: list<string>, 1: list<string>} $matches */
            $matches = [0 => ['test']];
        })->toThrow(TypeError::class, "is missing required key '1'");
    });
});
