<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Scalars;

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Domain\Car;

class Integer
{
    public function __construct(public int $value)
    {
    }
}

class Boolean
{
    public function __construct(public bool $value)
    {
    }
}

class Double
{
    public function __construct(public float $value)
    {
    }
}

/**
 * @param Integer $intObj
 */
function acceptIntegerObject(mixed $intObj): mixed
{
    return $intObj;
}

/**
 * @param Boolean $boolObj
 */
function acceptBooleanObject(mixed $boolObj): mixed
{
    return $boolObj;
}

/**
 * @param Double $doubleObj
 */
function acceptDoubleObject(mixed $doubleObj): mixed
{
    return $doubleObj;
}

/**
 * Legacy PHPDoc using scalar aliases:
 *
 * @param integer $scalarInt
 * @param boolean $scalarBool
 * @param double $scalarDouble
 */
function acceptLegacyScalarAliases(int $scalarInt, bool $scalarBool, float $scalarDouble): string
{
    return "{$scalarInt}:{$scalarBool}:{$scalarDouble}";
}

describe('Scalar Alias vs User Class Collision (Integer, Boolean, Double)', function () {
    test('accepts class named Integer matching parameter type contract @param Integer $intObj', function () {
        $int = new Integer(42);

        expect(acceptIntegerObject($int))->toBe($int);
    });

    test('accepts class named Boolean matching parameter type contract @param Boolean $boolObj', function () {
        $bool = new Boolean(true);

        expect(acceptBooleanObject($bool))->toBe($bool);
    });

    test('accepts class named Double matching parameter type contract @param Double $doubleObj', function () {
        $double = new Double(3.14);

        expect(acceptDoubleObject($double))->toBe($double);
    });

    test('still supports legacy scalar aliases integer, boolean, double on primitive values', function () {
        expect(acceptLegacyScalarAliases(42, true, 3.14))->toBe('42:1:3.14');

        expect(fn () => acceptLegacyScalarAliases('invalid', true, 3.14))
            ->toThrow(\TypeError::class)
        ;
    });

    test('rejects primitive int when class Integer is expected', function () {
        expect(fn () => acceptIntegerObject(42))
            ->toThrow(TypeError::class)
        ;
    });

    test('rejects unrelated class when class Integer is expected', function () {
        expect(fn () => acceptIntegerObject(new Car()))
            ->toThrow(TypeError::class)
        ;
    });
});