<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

use TypePHP\Exception\TypeError;

final class ByRefBagFixture
{
    public array $items = [1];

    public function &items(): array
    {
        return $this->items;
    }

    /**
     * @return list<positive-int>
     */
    public function &typedScores(): array
    {
        return $this->items;
    }
}

class ByRefGlobalHolder
{
    public static array $data = ['count' => 10];

    public static function &getData(): array
    {
        return self::$data;
    }
}

/**
 * Standalone function returning by reference
 */
$globalByRefList = [100];

function &testStandaloneReturnByRef(): array
{
    global $globalByRefList;

    return $globalByRefList;
}

describe('Return By Reference (function &name())', function () {
    test('preserves reference return and allows writing through reference without notices (User Bug Report)', function () {
        $bag = new ByRefBagFixture();

        $ref = &$bag->items();
        $ref[] = 2;

        expect($bag->items)->toBe([1, 2]);
    });

    test('preserves reference return on static methods', function () {
        $ref = &ByRefGlobalHolder::getData();
        $ref['count'] = 42;

        expect(ByRefGlobalHolder::$data['count'])->toBe(42);
    });

    test('preserves reference return on standalone functions', function () {
        global $globalByRefList;
        $globalByRefList = [100];

        $ref = &testStandaloneReturnByRef();
        $ref[] = 200;

        expect($globalByRefList)->toBe([100, 200]);
    });

    test('still enforces return type contract when by-ref variable violates contract on return', function () {
        $bag = new ByRefBagFixture();
        $bag->items = [10, -5];

        expect(fn () => $bag->typedScores())
            ->toThrow(TypeError::class, 'positive-int')
        ;
    });
});
