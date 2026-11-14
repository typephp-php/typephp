<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Tests\Fixtures\Generics\TestMutableHashMap;
use TypePHP\Tests\Fixtures\Generics\TestSetInterface;
use TypePHP\TypePHP;

if (PHP_VERSION_ID < 80400) {
    return;
}

require_once __DIR__ . '/../../Fixtures/Generics/ReturnGenericReificationFixtures.php';

describe('Generic Template Inference from Iterables of Array Shapes', function () {
    test('reproduces Noctud MapOrdering line 162 failure when constructing map from pairs', function () {
        $map = TestMutableHashMap::ofPairs([['aa', 'bb'], ['b', 'c'], ['cc', 'dd'], ['d', 'e']]);

        expect(TypePHP::getGenericTypes($map))->toBe([
            'K' => 'string',
            'V' => 'string',
        ]);

        $sorted = $map->sortedByKey(fn (string $k): int => \strlen($k));

        $keys = $sorted->keys;
        expect($keys)->toBeInstanceOf(TestSetInterface::class);
        expect(TypePHP::getGenericType($keys))->toBe('string');
    });
});
