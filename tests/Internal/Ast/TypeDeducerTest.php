<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast;

use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use TypePHP\Internal\Ast\TypeDeducer;

describe('TypeDeducer Unit Tests', function () {
    test('deduces element type from lists, arrays, and shapes', function () {
        expect(TypeDeducer::deduceElementType('list<positive-int>'))->toBe('positive-int')
            ->and(TypeDeducer::deduceElementType('int[]'))->toBe('int')
            ->and(TypeDeducer::deduceElementType('array<string, float>'))->toBe('float')
            ->and(TypeDeducer::deduceElementType('array{id: int, name: int}'))->toBe('int')
            ->and(TypeDeducer::deduceElementType('array{id: int, name: string}'))->toBe('(int|string)')
        ;
    });

    test('deduces key type from lists and maps', function () {
        expect(TypeDeducer::deduceKeyType('list<int>'))->toBe('int')
            ->and(TypeDeducer::deduceKeyType('int[]'))->toBe('int')
            ->and(TypeDeducer::deduceKeyType('array<string, int>'))->toBe('string')
        ;
    });

    test('deduces specific dimension type on array shapes', function () {
        $shape = 'array{id: positive-int, username: non-empty-string}';

        expect(TypeDeducer::deduceDimType($shape, new String_('id')))->toBe('positive-int')
            ->and(TypeDeducer::deduceDimType($shape, new String_('username')))->toBe('non-empty-string')
            ->and(TypeDeducer::deduceDimType('list<int>', new LNumber(0)))->toBe('int')
        ;
    });
});
