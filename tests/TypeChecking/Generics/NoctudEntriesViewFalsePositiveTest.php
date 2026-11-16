<?php

declare(strict_types=1);

use TypePHP\TypePHP;

if (PHP_VERSION_ID < 80400) {
    return;
}

require_once __DIR__ . '/../../Fixtures/Generics/NoctudEntriesViewFixture.php';

it('keeps $entries bound to MapEntry<string, int> after reading $values (immutable)', function () {
    $map = entriesViewImmutableMap(['a' => 1, 'b' => 2, 'c' => 3]);

    $map->values->toArray();

    expect($map->entries->toArray())->toHaveCount(3);
});

it('keeps $entries bound to MapEntry<string, int> after reading $values (mutable)', function () {
    $map = entriesViewMutableMap(['a' => 1, 'b' => 2, 'c' => 3]);

    $map->values->toArray();

    expect($map->entries->toArray())->toHaveCount(3);
});

it('keeps $entries valid when read before $values', function () {
    $map = entriesViewImmutableMap(['a' => 1, 'b' => 2, 'c' => 3]);

    expect($map->entries->toArray())->toHaveCount(3);

    $map->values->toArray();

    expect($map->entries->toArray())->toHaveCount(3);
});

it('keeps the shared store bound to K=string after reading $values', function () {
    $map = entriesViewImmutableMap(['a' => 1, 'b' => 2, 'c' => 3]);

    $map->values->toArray();

    expect(TypePHP::getGenericTypes($map->internalStore()))
        ->toMatchArray(['K' => 'string', 'V' => 'int'])
    ;
});

it('keeps V=int when $keys is read before $entries', function () {
    $map = entriesViewImmutableMap(['a' => 1, 'b' => 2, 'c' => 3]);

    iterator_to_array($map->keys);
    iterator_to_array($map->values);

    expect($map->entries->toArray())->toHaveCount(3);
    expect(TypePHP::getGenericTypes($map->internalStore()))
        ->toMatchArray(['K' => 'string', 'V' => 'int'])
    ;
});
