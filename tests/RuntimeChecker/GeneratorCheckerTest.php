<?php

declare(strict_types=1);

use TypePHP\Internal\Checker\GeneratorChecker;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Validator\TypeValidatorRegistry;

/**
 * @return Generator<string, positive-int, positive-int, void>
 */
function sampleGeneratorFixture(): Generator
{
    yield 'a' => 10;
}

/**
 * @return Generator<positive-int>
 */
function singleParamGeneratorFixture(): Generator
{
    yield 10;
}

describe('GeneratorChecker Unit Tests', function () {
    describe('checkYield', function () {
        test('accepts valid yielded key and value', function () {
            $registry = new TypeValidatorRegistry();

            $result = GeneratorChecker::checkYield('sampleGeneratorFixture', 'a', 10, $registry);

            expect($result)->toBe(10);
        });

        test('returns ErrorMessage on invalid yielded value', function () {
            $registry = new TypeValidatorRegistry();

            $result = GeneratorChecker::checkYield('sampleGeneratorFixture', 'a', -50, $registry);

            expect($result)->toBeInstanceOf(ErrorMessage::class)
                ->and($result->getMessage())->toContain('Return iterator value')
            ;
        });

        test('returns ErrorMessage on invalid yielded key', function () {
            $registry = new TypeValidatorRegistry();

            $result = GeneratorChecker::checkYield('sampleGeneratorFixture', 123, 10, $registry);

            expect($result)->toBeInstanceOf(ErrorMessage::class)
                ->and($result->getMessage())->toContain('Return iterator key')
            ;
        });

        test('validates single-template Generator<T>', function () {
            $registry = new TypeValidatorRegistry();

            expect(GeneratorChecker::checkYield('singleParamGeneratorFixture', null, 42, $registry))->toBe(42);
            expect(GeneratorChecker::checkYield('singleParamGeneratorFixture', null, -5, $registry))->toBeInstanceOf(ErrorMessage::class);
        });

        test('returns value directly when function has no return contract', function () {
            $registry = new TypeValidatorRegistry();

            $result = GeneratorChecker::checkYield('nonExistentFunction', 'k', 'v', $registry);
            expect($result)->toBe('v');
        });
    });

    describe('checkSend (TSend)', function () {
        test('accepts valid TSend input value', function () {
            $registry = new TypeValidatorRegistry();

            $result = GeneratorChecker::checkSend('sampleGeneratorFixture', 100, $registry);

            expect($result)->toBe(100);
        });

        test('returns ErrorMessage on invalid TSend input value', function () {
            $registry = new TypeValidatorRegistry();

            $result = GeneratorChecker::checkSend('sampleGeneratorFixture', -500, $registry);

            expect($result)->toBeInstanceOf(ErrorMessage::class)
                ->and($result->getMessage())->toContain('Generator sent value (TSend)')
            ;
        });

        test('returns null immediately when sendValue is null', function () {
            $registry = new TypeValidatorRegistry();

            expect(GeneratorChecker::checkSend('sampleGeneratorFixture', null, $registry))->toBeNull();
        });
    });
});
