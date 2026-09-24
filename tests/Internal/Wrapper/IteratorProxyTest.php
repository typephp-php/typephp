<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Wrapper;

use ArrayIterator;
use TypePHP\Internal\Wrapper\IteratorProxy;

describe('IteratorProxy Unit Tests', function () {
    test('iterates inner traversable cleanly while executing type check callback', function () {
        $inner = new ArrayIterator(['a' => 10, 'b' => 20]);
        $checkedKeys = [];

        $proxy = new IteratorProxy($inner, function ($key, $val) use (&$checkedKeys) {
            $checkedKeys[$key] = $val;
        });

        $out = [];
        foreach ($proxy as $k => $v) {
            $out[$k] = $v;
        }

        expect($out)->toBe(['a' => 10, 'b' => 20])
            ->and($checkedKeys)->toBe(['a' => 10, 'b' => 20])
        ;
    });

    test('allows multiple foreach iterations (rewindability)', function () {
        $inner = new ArrayIterator(['a' => 10, 'b' => 20]);
        $proxy = new IteratorProxy($inner, fn () => null);

        $count1 = 0;
        foreach ($proxy as $v) {
            $count1++;
        }

        $count2 = 0;
        foreach ($proxy as $v) {
            $count2++;
        }

        expect($count1)->toBe(2)
            ->and($count2)->toBe(2)
        ;
    });

    test('forwards count() call to inner countable iterator', function () {
        $inner = new ArrayIterator([1, 2, 3, 4, 5]);
        $proxy = new IteratorProxy($inner, fn () => null);

        expect($proxy->count())->toBe(5);
    });

    test('counts non-countable inner iterator using iterator_count fallback', function () {
        $gen = (function () {
            yield 1;
            yield 2;
            yield 3;
        })();

        $proxy = new IteratorProxy($gen, fn () => null);

        expect($proxy->count())->toBe(3);
    });

    test('returns inner iterator via getInnerIterator', function () {
        $inner = new ArrayIterator(['item' => 100]);
        $proxy = new IteratorProxy($inner, fn () => null);

        expect($proxy->getInnerIterator())->toBe($inner);
    });

    test('forwards custom method calls to inner iterator via __call', function () {
        $inner = new ArrayIterator(['a' => 10]);
        $proxy = new IteratorProxy($inner, fn () => null);

        expect($proxy->offsetExists('a'))->toBeTrue()
            ->and($proxy->offsetExists('z'))->toBeFalse()
        ;
    });
});
