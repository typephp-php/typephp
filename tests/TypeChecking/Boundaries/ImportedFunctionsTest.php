<?php

declare(strict_types=1);

use function TypePHP\Tests\Fixtures\Functions\calculateDiscount;
use function TypePHP\Tests\Fixtures\Functions\formatTag as makeHashTag;

describe('Imported Namespaced Function Contracts', function () {
    test('executes imported functions cleanly with valid parameters', function () {
        $discountedPrice = calculateDiscount(100, 20);
        expect($discountedPrice)->toBe(80);

        // Tests aliased function import: formatTag as makeHashTag
        $tag = makeHashTag('pestphp');
        expect($tag)->toBe('#pestphp');
    });

    test('throws TypeError when imported function receives invalid positive-int parameter', function () {
        // Price -50 violates positive-int contract
        expect(fn () => calculateDiscount(-50, 20))
            ->toThrow(TypeError::class, 'positive-int')
        ;
    });

    test('throws TypeError when imported function receives invalid int range parameter', function () {
        // Percentage 150 violates int<1, 100> range
        expect(fn () => calculateDiscount(100, 150))
            ->toThrow(TypeError::class, '100') // <-- Look for '100' in the error message
        ;
    });

    test('throws TypeError when aliased imported function receives empty string', function () {
        // Empty string violates non-empty-string contract
        expect(fn () => makeHashTag(''))
            ->toThrow(TypeError::class, 'non-empty-string')
        ;
    });
});
