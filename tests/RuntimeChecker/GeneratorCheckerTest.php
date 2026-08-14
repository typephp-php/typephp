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

describe('GeneratorChecker Unit Tests', function () {
    test('checkYield accepts valid yielded key and value', function () {
        $registry = new TypeValidatorRegistry();

        $result = GeneratorChecker::checkYield('sampleGeneratorFixture', 'a', 10, $registry);

        expect($result)->toBe(10);
    });

    test('checkYield returns ErrorMessage on invalid yielded value', function () {
        $registry = new TypeValidatorRegistry();

        $result = GeneratorChecker::checkYield('sampleGeneratorFixture', 'a', -50, $registry);

        expect($result)->toBeInstanceOf(ErrorMessage::class)
            ->and($result->getMessage())->toContain('Return iterator value')
        ;
    });

    test('checkSend accepts valid TSend input value', function () {
        $registry = new TypeValidatorRegistry();

        $result = GeneratorChecker::checkSend('sampleGeneratorFixture', 100, $registry);

        expect($result)->toBe(100);
    });

    test('checkSend returns ErrorMessage on invalid TSend input value', function () {
        $registry = new TypeValidatorRegistry();

        $result = GeneratorChecker::checkSend('sampleGeneratorFixture', -500, $registry);

        expect($result)->toBeInstanceOf(ErrorMessage::class)
            ->and($result->getMessage())->toContain('Generator sent value (TSend)')
        ;
    });
});