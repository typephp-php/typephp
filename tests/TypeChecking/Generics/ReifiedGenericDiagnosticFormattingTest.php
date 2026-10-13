<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Diagnostic\TypeFormatter;
use TypePHP\Internal\Util\Config;
use TypePHP\Tests\Fixtures\Domain\Dog;

/**
 * @template T
 */
class DiagnosticInnerBox
{
    /**
     * @param T $value
     */
    public function __construct(public mixed $value)
    {
    }
}

/**
 * @template T of DiagnosticInnerBox<int> | DiagnosticInnerBox<string>
 */
class DiagnosticOuterUnionContainer
{
    /**
     * @param T $box
     */
    public function __construct(public mixed $box)
    {
    }
}

/**
 * Parameter with DocBlock scalar refinement and native mixed type
 *
 * @param positive-int $num
 */
function acceptPositiveIntParam(mixed $num): void
{
}

/**
 * Parameter with DocBlock scalar union and native mixed type
 *
 * @param non-empty-string|positive-int $val
 */
function acceptScalarUnionParam(mixed $val): void
{
}

describe('Preserve Reified Nested Generics in TypeFormatter::formatGivenValue()', function () {
    beforeEach(function () {
        Config::reset();
    });

    afterEach(function () {
        Config::reset();
    });

    test('renders reified generic type in given clause on union bound failure (Reproduction)', function () {
        $floatBox = new DiagnosticInnerBox(3.14);

        expect(fn () => new DiagnosticOuterUnionContainer($floatBox))
            ->toThrow(
                TypeError::class,
                DiagnosticInnerBox::class . '<float> given'
            )
        ;
    });

    test('renders reified generic type in given clause on scalar parameter failure', function () {
        $stringBox = new DiagnosticInnerBox('hello');

        expect(fn () => acceptPositiveIntParam($stringBox))
            ->toThrow(
                TypeError::class,
                DiagnosticInnerBox::class . '<string> given'
            )
        ;
    });

    test('renders reified generic type in given clause on scalar union failure', function () {
        $dogBox = new DiagnosticInnerBox(new Dog());

        expect(fn () => acceptScalarUnionParam($dogBox))
            ->toThrow(
                TypeError::class,
                DiagnosticInnerBox::class . '<' . Dog::class . '> given'
            )
        ;
    });

    test('falls back to bare class name when redaction is enabled', function () {
        Config::set(['redact_values' => true]);

        $floatBox = new DiagnosticInnerBox(3.14);

        expect(fn () => acceptPositiveIntParam($floatBox))
            ->toThrow(
                TypeError::class,
                DiagnosticInnerBox::class . ' given'
            )
        ;

        expect(fn () => new DiagnosticOuterUnionContainer($floatBox))
            ->toThrow(
                TypeError::class,
                DiagnosticInnerBox::class . ' given'
            )
        ;
    });

    test('TypeFormatter::formatGivenValue directly formats reified generics and respects redaction', function () {
        $floatBox = new DiagnosticInnerBox(3.14);

        expect(TypeFormatter::formatGivenValue($floatBox))
            ->toBe(DiagnosticInnerBox::class . '<float>')
        ;

        expect(TypeFormatter::formatGivenValue($floatBox, isSensitive: true))
            ->toBe(DiagnosticInnerBox::class)
        ;
    });
});
