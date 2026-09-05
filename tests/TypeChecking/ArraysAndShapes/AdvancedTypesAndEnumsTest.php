<?php

declare(strict_types=1);

use TypePHP\Internal\Util\Config;
use TypePHP\Tests\Fixtures\Types\ConfigApp;
use TypePHP\Tests\Fixtures\Types\StatusEnum;
use TypePHP\Tests\Fixtures\Types\UserObjectShape;

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

/**
 * Parameter contract accepting general Object Shapes (any object or stdClass)
 *
 * @param object{id: positive-int, name: non-empty-string, role?: 'admin'|'user'} $payload
 */
function testObjectShapeContract(object $payload): bool
{
    return true;
}

/**
 * Parameter contract strictly accepting stdClass instances with Object Shapes
 *
 * @param stdClass{id: positive-int, name: non-empty-string} $payload
 */
function testStrictStdClassShapeContract(object $payload): bool
{
    return true;
}

/**
 * Return contract returning Object Shapes
 *
 * @return object{id: positive-int, name: non-empty-string}
 */
function testReturnObjectShapeContract(bool $valid): object
{
    if (! $valid) {
        $bad = new stdClass();
        $bad->id = -10;
        $bad->name = 'Alice';

        return $bad;
    }

    $good = new stdClass();
    $good->id = 10;
    $good->name = 'Alice';

    return $good;
}

/**
 * Parameter contract accepting PHP 8.1 Backed Enum cases
 *
 * @param StatusEnum::Active|StatusEnum::Pending $status
 */
function testEnumCaseContract(StatusEnum $status): bool
{
    return true;
}

/**
 * Return contract returning PHP 8.1 Backed Enum cases
 *
 * @return StatusEnum::Active|StatusEnum::Pending
 */
function testReturnEnumCaseContract(bool $valid): StatusEnum
{
    if (! $valid) {
        return StatusEnum::Inactive;
    }

    return StatusEnum::Active;
}

/**
 * Parameter contract accepting Class Constants as Types
 *
 * @param ConfigApp::MAX_LIMIT|ConfigApp::DEFAULT_ROLE $setting
 */
function testClassConstantContract(mixed $setting): bool
{
    return true;
}

/**
 * Return contract returning Class Constants as Types
 *
 * @return ConfigApp::MAX_LIMIT|ConfigApp::DEFAULT_ROLE
 */
function testReturnClassConstantContract(bool $valid): mixed
{
    if (! $valid) {
        return 'superadmin';
    }

    return 100;
}

describe('PHPDoc Object Shapes (object{prop: type})', function () {
    test('accepts stdClass object matching required object shape properties', function () {
        $payload = new stdClass();
        $payload->id = 42;
        $payload->name = 'Alice';
        $payload->role = 'admin';

        expect(testObjectShapeContract($payload))->toBeTrue();
    });

    test('accepts custom class object instance matching required object shape properties', function () {
        $user = new UserObjectShape(42, 'Alice', 'admin');

        expect(testObjectShapeContract($user))->toBeTrue();
    });

    test('accepts object shape with optional property omitted', function () {
        $payload = new stdClass();
        $payload->id = 42;
        $payload->name = 'Bob';

        expect(testObjectShapeContract($payload))->toBeTrue();
    });

    test('throws TypeError when object is missing required property in shape', function () {
        $payload = new stdClass();
        $payload->id = 42;

        expect(fn () => testObjectShapeContract($payload))
            ->toThrow(TypeError::class, "missing required property 'name'")
        ;
    });

    test('throws TypeError when object property violates property type constraint', function () {
        $user = new UserObjectShape(-10, 'Alice');

        expect(fn () => testObjectShapeContract($user))
            ->toThrow(TypeError::class, '->id')
        ;
    });

    test('validates return object shapes', function () {
        expect(testReturnObjectShapeContract(true))->toBeObject();

        expect(fn () => testReturnObjectShapeContract(false))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });

    test('enforces inline @var validation on object shapes', function () {
        /** @var object{id: positive-int, name: non-empty-string} $data */
        $data = new UserObjectShape(10, 'Alice');
        expect($data->name)->toBe('Alice');

        expect(fn () => $data = new UserObjectShape(-5, 'Alice'))
            ->toThrow(TypeError::class, 'Variable $data')
        ;
    });
});

describe('Strict stdClass Shapes (stdClass{prop: type})', function () {
    test('accepts stdClass instance matching the required shape', function () {
        $payload = new stdClass();
        $payload->id = 100;
        $payload->name = 'Alice';

        expect(testStrictStdClassShapeContract($payload))->toBeTrue();
    });

    test('throws TypeError when custom class instance is passed to stdClass shape', function () {
        $user = new UserObjectShape(100, 'Alice');

        expect(fn () => testStrictStdClassShapeContract($user))
            ->toThrow(TypeError::class)
        ;
    });

    test('throws TypeError when stdClass property violates shape constraint', function () {
        $payload = new stdClass();
        $payload->id = -50;
        $payload->name = 'Alice';

        expect(fn () => testStrictStdClassShapeContract($payload))
            ->toThrow(TypeError::class)
        ;
    });
});

describe('PHP 8.1 Backed Enums as Type Literals (Enum::Case)', function () {
    test('accepts declared Enum cases in union parameter', function () {
        expect(testEnumCaseContract(StatusEnum::Active))->toBeTrue();
        expect(testEnumCaseContract(StatusEnum::Pending))->toBeTrue();
    });

    test('throws TypeError when passing Enum case not in type union parameter', function () {
        expect(fn () => testEnumCaseContract(StatusEnum::Inactive))
            ->toThrow(TypeError::class)
        ;
    });

    test('validates return Enum cases', function () {
        expect(testReturnEnumCaseContract(true))->toBe(StatusEnum::Active);

        expect(fn () => testReturnEnumCaseContract(false))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });

    test('enforces inline @var validation on Enum cases', function () {
        /** @var StatusEnum::Active|StatusEnum::Pending $status */
        $status = StatusEnum::Active;
        expect($status)->toBe(StatusEnum::Active);

        expect(fn () => $status = StatusEnum::Inactive)
            ->toThrow(TypeError::class, 'Variable $status')
        ;
    });
});

describe('Class Constants as Type Literals (Class::CONST)', function () {
    test('accepts exact value defined by class constant parameter', function () {
        expect(testClassConstantContract(100))->toBeTrue();
        expect(testClassConstantContract('admin'))->toBeTrue();
    });

    test('throws TypeError when parameter value does not match class constant value', function () {
        expect(fn () => testClassConstantContract(999))
            ->toThrow(TypeError::class)
        ;

        expect(fn () => testClassConstantContract('superadmin'))
            ->toThrow(TypeError::class)
        ;
    });

    test('validates return class constants', function () {
        expect(testReturnClassConstantContract(true))->toBe(100);

        expect(fn () => testReturnClassConstantContract(false))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });

    test('enforces inline @var validation on class constants', function () {
        /** @var ConfigApp::MAX_LIMIT|ConfigApp::DEFAULT_ROLE $setting */
        $setting = 100;
        expect($setting)->toBe(100);

        expect(fn () => $setting = 999)
            ->toThrow(TypeError::class, 'Variable $setting')
        ;
    });
});
