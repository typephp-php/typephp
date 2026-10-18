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

/**
 * Generic interface with F-bound
 *
 * @template T of FBoundFormattingComparable<T>
 */
interface FBoundFormattingComparable
{
    public function compareTo(mixed $other): int;
}

/**
 * Concrete class implementing generic interface with concrete type argument
 *
 * @implements FBoundFormattingComparable<FBoundFormattingMoney>
 */
class FBoundFormattingMoney implements FBoundFormattingComparable
{
    public function __construct(public int $amount = 100)
    {
    }

    public function compareTo(mixed $other): int
    {
        return 0;
    }
}

/**
 * Non-generic class implementing generic interface with different type argument
 *
 * @implements FBoundFormattingComparable<FBoundFormattingMoney>
 */
class FBoundFormattingFakeMoney implements FBoundFormattingComparable
{
    public function __construct(public string $label = 'fake')
    {
    }

    public function compareTo(mixed $other): int
    {
        return 0;
    }
}

/**
 * Function expecting F-bounded instances
 *
 * @template T of FBoundFormattingComparable<T>
 *
 * @param T $a
 * @param T $b
 *
 * @return T
 */
function fBoundMaxOf(mixed $a, mixed $b): mixed
{
    return $a;
}

describe('Non-Generic Classes Implementing Generic Interfaces Diagnostic Formatting (TDD)', function () {
    test('TypeFormatter::formatGivenValue does not format non-generic class as generic (FakeMoney<Money> bug)', function () {
        $fakeMoney = new FBoundFormattingFakeMoney();

        expect(TypeFormatter::formatGivenValue($fakeMoney))
            ->toBe(FBoundFormattingFakeMoney::class)
            ->and(TypeFormatter::formatGivenValue($fakeMoney))->not()->toContain('<')
        ;
    });

    test('TemplateManager::inferTypeFromValue infers IdentifierTypeNode instead of GenericTypeNode for closed non-generic class', function () {
        $fakeMoney = new FBoundFormattingFakeMoney();

        $inferred = \TypePHP\Internal\Generics\TemplateManager::inferTypeFromValue($fakeMoney);

        expect($inferred)->toBeInstanceOf(\PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode::class)
            ->and((string) $inferred)->toBe(FBoundFormattingFakeMoney::class)
        ;
    });

    test('end-to-end function error message reports non-generic class name without phantom generic arguments', function () {
        $money = new FBoundFormattingMoney(100);
        $fakeMoney = new FBoundFormattingFakeMoney('wrong');

        expect(fn () => fBoundMaxOf($money, $fakeMoney))
            ->toThrow(function (TypeError $e) {
                expect($e->getMessage())->toContain(FBoundFormattingFakeMoney::class . ' given')
                    ->and($e->getMessage())->not()->toContain(FBoundFormattingFakeMoney::class . '<')
                ;
            })
        ;
    });

    test('still preserves and formats genuine generic classes with their type arguments (No regression)', function () {
        $box = new DiagnosticInnerBox(new Dog());

        expect(TypeFormatter::formatGivenValue($box))
            ->toBe(DiagnosticInnerBox::class . '<' . Dog::class . '>')
        ;
    });
});

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
