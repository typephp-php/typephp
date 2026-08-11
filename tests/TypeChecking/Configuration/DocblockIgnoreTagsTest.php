<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\IgnoreTags\IgnoredFile;
use TypePHP\Tests\Fixtures\IgnoreTags\IgnoredMethod;

/**
 * Standalone function with @typephp-ignore
 *
 * @typephp-ignore
 *
 * @param positive-int $id
 *
 * @return positive-int
 */
function testIgnoredFunction(int $id): int
{
    return $id;
}

describe('DocBlock Ignore Tags (@typephp-ignore & @typephp-ignore-file)', function () {
    test('skips type-checking on function marked with @typephp-ignore', function () {
        $result = testIgnoredFunction(-50);
        expect($result)->toBe(-50);
    });

    test('skips type-checking on method marked with @typephp-ignore while enforcing normal methods in same class', function () {
        $fixture = new IgnoredMethod();

        expect(fn() => $fixture->normalMethod(-5))
            ->toThrow(TypeError::class, 'positive-int');

        expect($fixture->ignoredMethod(-100))->toBe(-100);
    });

    test('skips type-checking on class property marked with @typephp-ignore', function () {
        $fixture = new IgnoredMethod();

        expect(fn() => $fixture->setNormalProperty(-5))
            ->toThrow(TypeError::class, 'Property');

        $fixture->setIgnoredProperty(-5);
        expect($fixture->ignoredProperty)->toBe(-5);
    });

    test('skips type-checking on entire file marked with @typephp-ignore-file', function () {
        $fileFixture = new IgnoredFile();

        $result = $fileFixture->process(-500);
        expect($result)->toBe(-500);
    });

    test('skips inline variable validation inside methods marked with @typephp-ignore', function () {
        $fixture = new class() {
            /**
             * @typephp-ignore
             * @param positive-int $id
             */
            public function ignoredMethodWithInlineVar(int $id): bool
            {
                /** @var string */
                $string = 1; // Invalid type assignment, but skipped because of @typephp-ignore above!

                return true;
            }
        };

        expect($fixture->ignoredMethodWithInlineVar(-500))->toBeTrue();
    });
});
